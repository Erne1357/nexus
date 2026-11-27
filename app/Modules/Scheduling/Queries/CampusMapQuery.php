<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Datos del mapa del campus del Inicio (tarea 1.24.1, ADR-0030): lo que el mapa (resources/js/ubicatec) necesita para
 * pintar los lugares reales y las clases del periodo en curso, en un solo arreglo que la página embebe como JSON.
 *
 * - El campus del mapa es el de los edificios con `map_key` (los que sincroniza `facilities:sync-campus-map`); sin
 *   ninguno, el mapa sale sin salones ni clases.
 * - El periodo lo recibe quien llama (el de consulta de la credencial, TP-04); sin periodo el mapa sale vacío.
 * - Las clases salen de la carga publicada de Nexus del periodo en ese campus si existe; si no, de las sesiones
 *   históricas del SII (`historical_group_sessions`, fuente `sii`). Las sesiones del SII guardan el aula y el RFC
 *   como texto: aquí se resuelven contra `rooms.code` del campus y `teachers.rfc`.
 * - Una clase es un grupo a la misma hora en uno o más días (0 = lunes), con su salón del mapa (`map_key`) o sin él
 *   (`room` nulo y `roomLabel` con la clave del aula).
 *
 * El mapa no antepone el grado al nombre del docente: el grado no considera el género (confirmado por el usuario el 26 de
 * noviembre de 2026, MP-03).
 *
 * Solo lectura (MP-01): se guarda en caché por periodo, campus y huella de los datos que lee; un periodo estable
 * (terminado o con siete días o más) vive 30 días y uno reciente diez minutos, y cualquier cambio de datos cambia la
 * huella, que se recalcula cada 60 segundos.
 */
final class CampusMapQuery
{
    public const int STABLE_TTL_SECONDS = 2592000;

    public const int LIVE_TTL_SECONDS = 600;

    public const int FINGERPRINT_TTL_SECONDS = 60;

    public const int STABLE_AFTER_DAYS = 7;

    /**
     * @return array{
     *     campus: ?string,
     *     term: array{code: string, name: string}|null,
     *     time: 'past'|'current'|'future',
     *     source: 'nexus'|'sii'|null,
     *     buildings: array<string, array{code: string, name: string}>,
     *     rooms: array<string, array{code: string, name: ?string, type: string, capacity: int, owner: ?string}>,
     *     departments: list<array{id: string, name: string, b: ?string, room: ?string, location: ?string, phone: ?string, email: ?string, web: ?string}>,
     *     subjects: list<array{id: string, name: string, area: ?string, cred: ?int}>,
     *     groups: list<array{id: string, subject: string, code: string, area: ?string, career: ?string, turno: string}>,
     *     profs: list<array{id: string, name: string, area: ?string, email: ?string}>,
     *     classes: list<array{subject: string, group: string, prof: ?string, room: ?string, roomLabel: ?string, days: list<int>, hour: int, end: int}>
     * }
     */
    public function forTerm(?Term $term): array
    {
        // El tiempo del periodo va fuera de la caché (que vive hasta 30 días): depende de la fecha de hoy (MP-02).
        return [...$this->cachedMap($term), 'time' => $this->timeOf($term)];
    }

    /**
     * El mapa del periodo de consulta del Actor. Con el puesto de alumno activo se recorta después de la caché
     * compartida (la caché no lleva datos de ningún alumno): solo las clases de los grupos que cursa en el periodo, los
     * docentes de su departamento académico y de sus propias clases, y los grupos y materias de esas clases; los
     * lugares (edificios, salones y departamentos) quedan completos (MP-04). `scope` es `student` en ese caso.
     *
     * @return array<string, mixed>
     */
    public function forActor(Actor $actor): array
    {
        $term = $actor->viewTerm();
        $map = $this->forTerm($term);

        if (! $actor->actsAsStudent() || $term === null) {
            return [...$map, 'scope' => null];
        }

        return $this->forStudent($map, $term, (string) $actor->user()?->getKey());
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function forStudent(array $map, Term $term, string $userId): array
    {
        $student = DB::table('students')
            ->leftJoin('careers', 'careers.id', '=', 'students.career_id')
            ->leftJoin('departments', 'departments.id', '=', 'careers.department_id')
            ->where('students.user_id', $userId)
            ->whereNull('students.deleted_at')
            ->first(['students.id', 'departments.code as department']);

        // Las clases de la carga publicada de Nexus no tienen inscripción todavía (fase 9): el alumno no las ve (supuesto por confirmar).
        $keys = $student === null || $map['source'] !== 'sii' ? [] : $this->studentGroupKeys($student->id, $term);
        $classes = array_values(array_filter($map['classes'], fn (array $class): bool => isset($keys[$class['group']])));
        $subjects = array_flip(array_column($classes, 'subject'));
        $teachers = array_flip(array_filter(array_column($classes, 'prof')));

        return [
            ...$map,
            'scope' => 'student',
            'classes' => $classes,
            'groups' => array_values(array_filter($map['groups'], fn (array $group): bool => isset($keys[$group['id']]))),
            'subjects' => array_values(array_filter($map['subjects'], fn (array $subject): bool => isset($subjects[$subject['id']]))),
            'profs' => array_values(array_filter($map['profs'], fn (array $prof): bool => isset($teachers[$prof['id']]) || ($student?->department !== null && $prof['area'] === $student->department))),
        ];
    }

    /**
     * Las llaves del mapa (`clave de la materia·grupo`) de los grupos del SII que el alumno cursa en el periodo: su
     * kárdex del periodo lleva la clave y el grupo del SII, que se buscan entre los grupos históricos del periodo.
     *
     * @return array<string, true>
     */
    private function studentGroupKeys(string $studentId, Term $term): array
    {
        return DB::table('kardex_records as kr')
            ->join('historical_groups as hg', function ($join): void {
                $join->on('hg.term_code', '=', 'kr.term_code')
                    ->on('hg.sii_subject_code', '=', 'kr.sii_subject_code')
                    ->on('hg.group_code', '=', 'kr.sii_group_code');
            })
            ->join('subjects', 'subjects.id', '=', 'hg.subject_id')
            ->where('kr.student_id', $studentId)
            ->where('kr.term_code', $term->code)
            ->whereNull('kr.deleted_at')
            ->where('hg.source', 'sii')
            ->get(['subjects.code', 'hg.group_code'])
            ->mapWithKeys(fn (object $row): array => [$this->groupKey($row->code, $row->group_code) => true])
            ->all();
    }

    /**
     * Cuándo cae el periodo respecto a hoy (America/Ciudad_Juarez): `past` si ya terminó, `future` si aún no empieza y
     * `current` si no (también sin periodo). Con `past` o `future` el mapa no muestra «ahora» ni ocupación en vivo (MP-02).
     *
     * @return 'past'|'current'|'future'
     */
    public function timeOf(?Term $term): string
    {
        if ($term === null) {
            return 'current';
        }

        $today = CarbonImmutable::now('America/Ciudad_Juarez')->startOfDay();

        return match (true) {
            CarbonImmutable::parse($term->ends_on->toDateString(), 'America/Ciudad_Juarez')->lessThan($today) => 'past',
            CarbonImmutable::parse($term->starts_on->toDateString(), 'America/Ciudad_Juarez')->greaterThan($today) => 'future',
            default => 'current',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedMap(?Term $term): array
    {
        $campusId = DB::table('buildings')->whereNotNull('map_key')->whereNull('deleted_at')->value('campus_id');

        if ($term === null || $campusId === null) {
            return $this->build($term, $campusId, null);
        }

        // Una sola llave por periodo y campus que guarda la huella con el mapa: si la huella cambió, se rehace y se
        // sobrescribe, así las versiones viejas no se acumulan en Valkey.
        $fingerprint = $this->fingerprint($term, $campusId);
        $key = 'scheduling:campus-map:v4:'.$term->code.':'.$campusId;
        $cached = Cache::get($key);

        if (is_array($cached) && ($cached['fp'] ?? null) === $fingerprint) {
            return $cached['map'];
        }

        $map = $this->build($term, $campusId, $this->hasPublishedLoad($term, $campusId) ? 'nexus' : 'sii');
        Cache::put($key, ['fp' => $fingerprint, 'map' => $map], $this->isStable($term) ? self::STABLE_TTL_SECONDS : self::LIVE_TTL_SECONDS);

        return $map;
    }

    /**
     * Huella de todo lo que pinta el mapa del periodo en el campus: conteo, última modificación y suma de las
     * marcas de tiempo de cada tabla que lee, con el periodo y el campus (una sola consulta con subselects); la suma
     * nota también dos cambios en el mismo segundo. Se guarda 60 segundos; pasado ese lapso un cambio ya cambia la llave de
     * la caché (MP-01).
     */
    public function fingerprint(Term $term, string $campusId): string
    {
        return Cache::remember(
            'scheduling:campus-map:fp:'.$term->code.':'.$campusId,
            self::FINGERPRINT_TTL_SECONDS,
            function () use ($term, $campusId): string {
                $parts = [
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from academic_loads where term_id = :term',
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from groups where term_id = :term and campus_id = :campus',
                    'select count(*) c, max(s.updated_at) m, sum(extract(epoch from s.updated_at)) e from group_sessions s join groups g on g.id = s.group_id where g.term_id = :term and g.campus_id = :campus',
                    "select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from historical_groups where term_code = :code and source = 'sii' and campus_id = :campus",
                    "select count(*) c, max(s.updated_at) m, sum(extract(epoch from s.updated_at)) e from historical_group_sessions s join historical_groups g on g.id = s.historical_group_id where g.term_code = :code and g.source = 'sii' and g.campus_id = :campus",
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from rooms where campus_id = :campus',
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from buildings where campus_id = :campus',
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from teachers',
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from teacher_departments',
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from departments',
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from subjects',
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from careers',
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from terms where id = :term',
                    'select count(*) c, max(updated_at) m, sum(extract(epoch from updated_at)) e from campuses where id = :campus',
                ];
                $select = [];
                $from = [];
                foreach ($parts as $i => $sql) {
                    $select[] = "t{$i}.c as c{$i}, t{$i}.m as m{$i}, t{$i}.e as e{$i}";
                    $from[] = "($sql) t{$i}";
                }

                $row = DB::selectOne('select '.implode(', ', $select).' from '.implode(' cross join ', $from), ['term' => $term->id, 'campus' => $campusId, 'code' => $term->code]);

                return hash('xxh128', (string) json_encode($row));
            },
        );
    }

    /**
     * Estable: el periodo ya terminó o lleva al menos siete días corridos; ahí la carga ya no se mueve de forma
     * habitual y la caché puede vivir un mes (la huella la invalida igual si algo cambia).
     */
    private function isStable(Term $term): bool
    {
        $today = CarbonImmutable::now('America/Ciudad_Juarez')->startOfDay();
        $starts = CarbonImmutable::parse($term->starts_on->toDateString(), 'America/Ciudad_Juarez');
        $ends = CarbonImmutable::parse($term->ends_on->toDateString(), 'America/Ciudad_Juarez');

        return $ends->lessThan($today) || $today->greaterThanOrEqualTo($starts->addDays(self::STABLE_AFTER_DAYS));
    }

    private function hasPublishedLoad(Term $term, string $campusId): bool
    {
        return DB::table('groups')
            ->join('academic_loads', function ($join): void {
                $join->on('academic_loads.term_id', '=', 'groups.term_id')
                    ->on('academic_loads.modality_id', '=', 'groups.modality_id')
                    ->on('academic_loads.department_id', '=', 'groups.department_id');
            })
            ->where('groups.term_id', $term->id)
            ->where('groups.campus_id', $campusId)
            ->where('groups.status', '!=', 'cancelled')
            ->where('academic_loads.status', LoadStatus::Published->value)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function build(?Term $term, ?string $campusId, ?string $source): array
    {
        $rooms = $campusId === null ? collect() : DB::table('rooms')
            ->leftJoin('departments', 'departments.id', '=', 'rooms.department_id')
            ->where('rooms.campus_id', $campusId)
            ->whereNull('rooms.deleted_at')
            ->where('rooms.is_active', true)
            ->get(['rooms.code', 'rooms.name', 'rooms.type', 'rooms.capacity', 'rooms.map_key', 'departments.code as owner']);

        $sessions = match ($source) {
            'nexus' => $this->nexusSessions($term, $campusId),
            'sii' => $this->siiSessions($term, $campusId, $rooms),
            default => collect(),
        };

        $subjects = $this->subjects($sessions->pluck('subject_id')->filter()->unique()->values());
        // Una sesión cuya materia no se encuentra no tumba el Inicio de nadie: se omite.
        $sessions = $sessions->filter(fn (object $session): bool => isset($subjects[$session->subject_id]))->values();
        $teacherIds = $sessions->pluck('teacher_id')->filter()->unique()->values();

        return [
            'campus' => $campusId === null ? null : DB::table('campuses')->where('id', $campusId)->value('name'),
            'term' => $term === null ? null : ['code' => $term->code, 'name' => $term->name],
            'source' => $source,
            'buildings' => $this->buildings($campusId),
            'rooms' => $rooms->filter(fn (object $room): bool => $room->map_key !== null)
                ->mapWithKeys(fn (object $room): array => [$room->map_key => [
                    'code' => $room->code,
                    'name' => $room->name,
                    'type' => $room->type,
                    'capacity' => (int) $room->capacity,
                    'owner' => $room->owner,
                ]])->all(),
            'departments' => $this->departments(),
            'subjects' => array_values(array_map(fn (array $subject): array => [
                'id' => $subject['code'],
                'name' => $subject['name'],
                'area' => $subject['area'],
                'cred' => $subject['cred'],
            ], $subjects)),
            'groups' => $this->groups($sessions, $subjects),
            'profs' => $this->teachers($teacherIds),
            'classes' => $this->classes($sessions, $subjects),
        ];
    }

    /**
     * @return array<string, array{code: string, name: string}>
     */
    private function buildings(?string $campusId): array
    {
        if ($campusId === null) {
            return [];
        }

        return DB::table('buildings')
            ->where('campus_id', $campusId)
            ->whereNotNull('map_key')
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['map_key', 'code', 'name'])
            ->mapWithKeys(fn (object $building): array => [$building->map_key => ['code' => $building->code, 'name' => $building->name]])
            ->all();
    }

    /**
     * Departamentos activos con su ubicación y contacto del mapa (tarea 1.24.2): `map_key` es «edificio|oficina».
     *
     * @return list<array<string, ?string>>
     */
    private function departments(): array
    {
        return DB::table('departments')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name', 'map_key', 'location', 'phone', 'email', 'website'])
            ->map(function (object $department): array {
                [$building, $office] = $department->map_key === null ? [null, null] : array_pad(explode('|', $department->map_key, 2), 2, null);

                return [
                    'id' => $department->code,
                    'name' => $department->name,
                    'b' => $building,
                    'room' => $office,
                    'location' => $department->location,
                    'phone' => $department->phone,
                    'email' => $department->email,
                    'web' => $department->website,
                ];
            })->values()->all();
    }

    /**
     * Sesiones de la carga publicada de Nexus del periodo en el campus del mapa.
     *
     * @return Collection<int, object>
     */
    private function nexusSessions(Term $term, string $campusId): Collection
    {
        return DB::table('group_sessions')
            ->join('groups', 'groups.id', '=', 'group_sessions.group_id')
            ->join('academic_loads', function ($join): void {
                $join->on('academic_loads.term_id', '=', 'groups.term_id')
                    ->on('academic_loads.modality_id', '=', 'groups.modality_id')
                    ->on('academic_loads.department_id', '=', 'groups.department_id');
            })
            ->leftJoin('rooms', 'rooms.id', '=', 'group_sessions.room_id')
            ->leftJoin('careers', 'careers.id', '=', 'groups.owner_career_id')
            ->where('groups.term_id', $term->id)
            ->where('groups.campus_id', $campusId)
            ->where('groups.status', '!=', 'cancelled')
            ->where('group_sessions.is_active', true)
            ->where('academic_loads.status', LoadStatus::Published->value)
            ->get([
                'groups.id as group_id', 'groups.subject_id', 'groups.code as group_code', 'careers.code as career',
                'group_sessions.day', 'group_sessions.starts_at', 'group_sessions.ends_at',
                'rooms.map_key as room_map_key', 'rooms.code as room_code',
                DB::raw('coalesce(group_sessions.teacher_id, groups.teacher_id) as teacher_id'),
            ]);
    }

    /**
     * Sesiones históricas del SII del periodo en el campus del mapa; el aula se resuelve por la clave normalizada del
     * salón del campus y el docente por su RFC.
     *
     * @param  Collection<int, object>  $rooms
     * @return Collection<int, object>
     */
    private function siiSessions(Term $term, string $campusId, Collection $rooms): Collection
    {
        $mapKeys = $rooms->pluck('map_key', 'code');

        return DB::table('historical_group_sessions as s')
            ->join('historical_groups as g', 'g.id', '=', 's.historical_group_id')
            ->leftJoin('teachers', function ($join): void {
                $join->on('teachers.rfc', '=', 's.teacher_rfc')->whereNull('teachers.deleted_at');
            })
            ->leftJoin('careers', 'careers.id', '=', 'g.career_id')
            ->where('g.term_code', $term->code)
            ->where('g.source', 'sii')
            ->where('g.campus_id', $campusId)
            ->get([
                'g.id as group_id', 'g.subject_id', 'g.group_code', 'careers.code as career',
                's.day', 's.starts_at', 's.ends_at', 's.room_code', 'teachers.id as teacher_id',
            ])
            ->each(function (object $session) use ($mapKeys): void {
                $session->room_map_key = $session->room_code === null ? null : ($mapKeys[$session->room_code] ?? null);
            });
    }

    /**
     * Las materias de las sesiones, por id, ordenadas por clave.
     *
     * @param  Collection<int, string>  $ids
     * @return array<string, array{code: string, name: string, area: ?string, cred: ?int}>
     */
    private function subjects(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('subjects')
            ->leftJoin('departments', 'departments.id', '=', 'subjects.department_id')
            ->whereIn('subjects.id', $ids)
            ->orderBy('subjects.code')
            ->get(['subjects.id', 'subjects.code', 'subjects.name', 'subjects.credits', 'departments.code as area'])
            ->mapWithKeys(fn (object $subject): array => [$subject->id => [
                'code' => $subject->code,
                'name' => $subject->name,
                'area' => $subject->area,
                'cred' => $subject->credits === null ? null : (int) $subject->credits,
            ]])->all();
    }

    /**
     * @param  Collection<int, string>  $ids
     * @return list<array{id: string, name: string, area: ?string, email: ?string}>
     */
    private function teachers(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        $primary = DB::table('teacher_departments')
            ->join('departments', 'departments.id', '=', 'teacher_departments.department_id')
            ->whereIn('teacher_departments.teacher_id', $ids)
            ->whereNull('teacher_departments.deleted_at')
            ->orderByDesc('teacher_departments.is_primary')
            ->orderBy('teacher_departments.created_at')
            ->get(['teacher_departments.teacher_id', 'departments.code'])
            ->unique('teacher_id')
            ->pluck('code', 'teacher_id');

        return DB::table('teachers')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'institutional_email'])
            ->map(fn (object $teacher): array => [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'area' => $primary[$teacher->id] ?? null,
                // El correo institucional va para todo el que tiene sesión, alumnos incluidos (confirmado por el usuario el 20 de noviembre de 2026).
                'email' => $teacher->institutional_email,
            ])->values()->all();
    }

    /**
     * @param  Collection<int, object>  $sessions
     * @param  array<string, array{code: string, name: string, area: ?string, cred: ?int}>  $subjects
     * @return list<array{id: string, subject: string, code: string, area: ?string, career: ?string, turno: string}>
     */
    private function groups(Collection $sessions, array $subjects): array
    {
        return $sessions->groupBy('group_id')->map(function (Collection $rows) use ($subjects): array {
            $first = $rows->first();
            $subject = $subjects[$first->subject_id];

            return [
                'id' => $this->groupKey($subject['code'], $first->group_code),
                'subject' => $subject['code'],
                'code' => $first->group_code,
                'area' => $subject['area'],
                'career' => $first->career,
                // Turno por la hora de la primera sesión: antes de las 14:00, matutino (supuesto por confirmar).
                'turno' => (int) substr((string) $rows->min('starts_at'), 0, 2) < 14 ? 'Matutino' : 'Vespertino',
            ];
        })->sortBy('id')->values()->all();
    }

    /**
     * Junta las sesiones de un grupo con la misma hora, salón y docente en una clase con sus días (0 = lunes).
     *
     * @param  Collection<int, object>  $sessions
     * @param  array<string, array{code: string, name: string, area: ?string, cred: ?int}>  $subjects
     * @return list<array{subject: string, group: string, prof: ?string, room: ?string, roomLabel: ?string, days: list<int>, hour: int, end: int}>
     */
    private function classes(Collection $sessions, array $subjects): array
    {
        return $sessions
            ->groupBy(fn (object $s): string => implode('|', [$s->group_id, $s->starts_at, $s->ends_at, $s->room_code ?? '', $s->teacher_id ?? '']))
            ->map(function (Collection $rows) use ($subjects): array {
                $first = $rows->first();
                $code = $subjects[$first->subject_id]['code'];
                [$startH, $startM] = array_map('intval', explode(':', (string) $first->starts_at));
                [$endH, $endM] = array_map('intval', explode(':', (string) $first->ends_at));

                return [
                    'subject' => $code,
                    'group' => $this->groupKey($code, $first->group_code),
                    'prof' => $first->teacher_id,
                    'room' => $first->room_map_key,
                    'roomLabel' => $first->room_code,
                    'days' => $rows->pluck('day')->map(fn ($day): int => (int) $day - 1)->unique()->sort()->values()->all(),
                    'hour' => $startH,
                    // Hora en que termina, redondeada hacia arriba (una clase de 7:00 a 8:40 ocupa 7 y 8).
                    'end' => $endM > 0 ? $endH + 1 : $endH,
                ];
            })
            ->sortBy(fn (array $class): string => sprintf('%02d|%s', $class['hour'], $class['group']))
            ->values()->all();
    }

    private function groupKey(string $subjectCode, string $groupCode): string
    {
        return $subjectCode.'·'.$groupCode;
    }
}
