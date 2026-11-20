<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
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
 * - El periodo es el que contiene `$day` (`starts_on` a `ends_on`); si ninguno, el último que ya terminó (supuesto por
 *   confirmar, S1 de 1.24).
 * - Las clases salen de la carga publicada de Nexus del periodo en ese campus si existe; si no, de las sesiones
 *   históricas del SII (`historical_group_sessions`, fuente `sii`). Las sesiones del SII guardan el aula y el RFC
 *   como texto: aquí se resuelven contra `rooms.code` del campus y `teachers.rfc`.
 * - Una clase es un grupo a la misma hora en uno o más días (0 = lunes), con su salón del mapa (`map_key`) o sin él
 *   (`room` nulo y `roomLabel` con la clave del aula).
 *
 * Solo lectura; se guarda en caché diez minutos por periodo, fuente y día.
 */
final class CampusMapQuery
{
    private const int TTL_SECONDS = 600;

    /**
     * @return array{
     *     campus: ?string,
     *     term: array{code: string, name: string}|null,
     *     source: 'nexus'|'sii'|null,
     *     buildings: array<string, array{code: string, name: string}>,
     *     rooms: array<string, array{code: string, name: ?string, type: string, capacity: int, owner: ?string}>,
     *     departments: list<array{id: string, name: string, b: ?string, room: ?string, location: ?string, phone: ?string, email: ?string, web: ?string}>,
     *     subjects: list<array{id: string, name: string, area: ?string, cred: ?int}>,
     *     groups: list<array{id: string, subject: string, code: string, area: ?string, career: ?string, turno: string}>,
     *     profs: list<array{id: string, name: string, title: ?string, area: ?string, email: ?string}>,
     *     classes: list<array{subject: string, group: string, prof: ?string, room: ?string, roomLabel: ?string, days: list<int>, hour: int, end: int}>
     * }
     */
    public function forDay(CarbonImmutable $day): array
    {
        $campusId = DB::table('buildings')->whereNotNull('map_key')->whereNull('deleted_at')->value('campus_id');
        $term = $this->termOn($day);
        $source = $term === null || $campusId === null ? null : ($this->hasPublishedLoad($term, $campusId) ? 'nexus' : 'sii');
        $key = 'scheduling:campus-map:v1:'.($term?->code ?? 'none').':'.($source ?? 'none').':'.($campusId ?? 'none').':'.$day->toDateString();

        return Cache::remember($key, self::TTL_SECONDS, fn (): array => $this->build($term, $campusId, $source));
    }

    private function termOn(CarbonImmutable $day): ?Term
    {
        return Term::query()->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)->orderByDesc('code')->first()
            ?? Term::query()->whereDate('ends_on', '<', $day)->orderByDesc('ends_on')->first();
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

        $subjects = $this->subjects($sessions->pluck('subject_id')->unique()->values());
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
     * @return list<array{id: string, name: string, title: ?string, area: ?string, email: ?string}>
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
            ->get(['id', 'name', 'degree', 'institutional_email'])
            ->map(fn (object $teacher): array => [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'title' => $teacher->degree,
                'area' => $primary[$teacher->id] ?? null,
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
