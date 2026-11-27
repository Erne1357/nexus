<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Rules\SubjectTeacherHours;
use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSession;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Scheduling\Queries\SiiTeacherScheduleQuery;
use App\Modules\Scheduling\Rules\Constraints\CampusTravel;
use App\Modules\Scheduling\Rules\TeacherHourLimit;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Support\PublishedStamps;
use App\Modules\Staffing\Support\SessionLabels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * «Mi carga» (tarea 3.10): los grupos no cancelados del docente en el periodo, de todos los campus, acomodados por día
 * (lunes a viernes; el sábado solo si tiene sesiones ese día) con los huecos y los traslados entre campus de una
 * sesión a la siguiente, y la cuenta de sus horas (la de HC-08, TeacherHourLimit).
 *
 * Lo publicado o lo vivo se decide por carga (periodo, modalidad y departamento, M1; P8, confirmado el 10 de noviembre de
 * 2026): de una carga con versión publicada salen sus grupos de la última versión, también si se reabrió (supuesto
 * por confirmar); de las demás, de las tablas vivas. Así, el grupo que se le asignó después de publicar no se ve hasta
 * la siguiente versión, y el publicado que después se reasignó o se canceló sí. Las horas asignadas suman los grupos
 * que se ven, para que la cuenta cuadre con la pantalla; la base, las externas y el límite son los de ahora.
 *
 * Un traslado lleva sus minutos libres y el aviso de SC-09 si son menos de `staffing.min_campus_travel_minutes`: la
 * regla de CampusTravel (dos sesiones seguidas del mismo día en campus distintos) aplicada a lo que se ve, publicado o
 * vivo, porque el validador solo conoce lo vivo (supuesto por confirmar); con traslado no se marca aparte el hueco. Un
 * número constante de consultas.
 */
final class MyLoadQuery
{
    public function __construct(
        private readonly ScheduleValidator $validator,
        private readonly ScheduleStateQuery $states,
        private readonly PublishedLoadsQuery $published,
        private readonly SiiTeacherScheduleQuery $sii,
        private readonly ExtraHoursStatusQuery $extraHours,
    ) {}

    /**
     * @return array{
     *     groups: list<array{id: string, code: string, subject_code: string, subject_name: string, campus_code: string, hours: int, schedule: list<array{days: string, time: string}>}>,
     *     home: ?string,
     *     travel_minutes: int,
     *     days: array<int, array{name: string, short: string, hours: int, blocks: list<array<string, mixed>>}>,
     *     hours: array{base: int, limit: int, tolerance: int, external: int, assigned: int, remaining: int, sources: list<array{source: string, hours: int}>},
     *     published: list<array{scope: ?string, version: int, published_at: CarbonImmutable}>,
     *     source: 'nexus'|'sii'
     * }
     */
    public function forTeacher(Teacher $teacher, Term $term): array
    {
        $groups = $this->groups($teacher, $term);
        $source = 'nexus';

        // Sin grupos en Nexus (ni publicados ni vivos), el periodo en curso sale del horario del SII por RFC (#755).
        if ($groups === []) {
            $groups = $this->siiGroups($teacher, $term);
            $source = $groups === [] ? 'nexus' : 'sii';
        }

        // El validador es scoped y guarda la foto que ya leyó en la petición: se olvida para leer la de ahora.
        $this->validator->forget();
        $state = $this->validator->stateFor($term->id, null);
        $travel = (int) $state->setting('staffing.min_campus_travel_minutes', CampusTravel::DEFAULT_MINUTES);
        $blocks = [];

        foreach ($groups as $group) {
            foreach ($group['sessions'] as $session) {
                $blocks[$session['day']][] = [
                    'group_id' => $group['id'],
                    'group_code' => $group['code'],
                    'subject_name' => $group['subject_name'],
                    'subject_code' => $group['subject_code'],
                    'campus_code' => $group['campus_code'],
                    'campus_name' => $group['campus_name'],
                    'counts_teacher_hours' => $group['counts_teacher_hours'] ?? true,
                    'room' => $session['room'],
                    'kind' => $session['kind'],
                    'time' => $session['time'],
                    'start' => $session['start'],
                    'end' => $session['end'],
                ];
            }
        }

        $days = [];

        foreach (SessionLabels::DAY_NAMES as $number => $name) {
            if ($number === 6 && ! isset($blocks[6])) {
                continue;
            }

            $day = collect($blocks[$number] ?? [])->sortBy('start')->values()->all();

            foreach ($day as $index => &$block) {
                $previous = $day[$index - 1] ?? null;
                $free = $previous === null ? 0 : $block['start'] - $previous['end'];
                $moves = $previous !== null && $previous['campus_code'] !== $block['campus_code'];
                $block['transfer'] = $moves ? [
                    'from' => $previous['campus_code'],
                    'free_minutes' => $free,
                    'short' => $free >= 0 && $free < $travel,
                ] : null;
                $block['gap'] = ! $moves && $free > 0 ? $free : null;
            }
            unset($block);

            $days[$number] = [
                'name' => $name,
                'short' => SessionLabels::DAYS[$number],
                'hours' => (int) round(array_sum(array_map(fn (array $block): int => $block['end'] - $block['start'], $day)) / 60),
                'blocks' => $day,
            ];
        }

        return [
            'groups' => array_map(fn (array $group): array => [
                'id' => $group['id'],
                'code' => $group['code'],
                'subject_code' => $group['subject_code'],
                'subject_name' => $group['subject_name'],
                'campus_code' => $group['campus_code'],
                'hours' => $group['hours'],
                'counts_teacher_hours' => $group['counts_teacher_hours'] ?? true,
                'schedule' => $group['schedule'],
            ], $groups),
            'days' => $days,
            'home' => collect($groups)->countBy('campus_code')->sortDesc()->keys()->first(),
            'travel_minutes' => $travel,
            'hours' => $this->hours($teacher, $term, $state, array_sum(array_column($groups, 'hours'))),
            'published' => PublishedStamps::of(array_map(fn (array $group): array => [
                'department' => $group['department'],
                'campus' => $group['campus_code'],
                'published' => $group['published'],
            ], $groups)),
            'source' => $source,
        ];
    }

    /**
     * Si el docente tiene grupos de Nexus (publicados o vivos) en el periodo; no mira el SII.
     */
    public function hasNexusGroups(Teacher $teacher, Term $term): bool
    {
        return $this->groups($teacher, $term) !== [];
    }

    /**
     * Los ids de los grupos que muestra «Mi carga», sin armar la semana ni la cuenta de horas.
     *
     * @return list<string>
     */
    public function groupIds(Teacher $teacher, Term $term): array
    {
        return array_column($this->groups($teacher, $term), 'id');
    }

    /**
     * Los grupos que se ven, cada uno de lo publicado o de lo vivo según su carga, por clave de grupo y de materia.
     *
     * @return list<array{id: string, code: string, subject_code: string, subject_name: string, campus_code: string, campus_name: string, department: string, hours: int, schedule: list<array{days: string, time: string}>, sessions: list<array{day: int, start: int, end: int, time: string, room: ?string, kind: string}>, published: ?array{version: int, at: CarbonImmutable}}>
     */
    private function groups(Teacher $teacher, Term $term): array
    {
        $published = $this->published->forTerm($term);

        $live = Group::query()
            ->active()
            ->where('term_id', $term->id)
            ->where('teacher_id', $teacher->id)
            ->with(['subject:id,code,name,theory_hours,practice_hours,counts_teacher_hours', 'campus:id,code,name', 'department:id,name', 'sessions' => fn ($sessions) => $sessions->with('room:id,code')])
            ->get()
            ->reject(fn (Group $group): bool => $published->isPublished($group->modality_id, $group->department_id));

        $publishedGroups = $published->teacher($teacher->id);
        // PL-02: la versión publicada no guarda si la materia cuenta en la carga del docente; se lee de la materia de hoy.
        $countsBySubject = $publishedGroups === [] ? collect() : Subject::query()->withTrashed()
            ->whereIn('id', array_values(array_filter(array_map(fn (array $entry): string => (string) ($entry['group']->subject['id'] ?? ''), $publishedGroups), Str::isUuid(...))))
            ->pluck('counts_teacher_hours', 'id');

        $groups = [
            ...$live->map(self::fromLive(...))->values()->all(),
            ...array_map(fn (array $entry): array => self::fromPublished($entry['group'], $entry['campus'], $entry['department']['name'],
                (bool) ($countsBySubject[$entry['group']->subject['id']] ?? true)), $publishedGroups),
        ];

        usort($groups, fn (array $a, array $b): int => strnatcmp($a['code'], $b['code']) ?: strcmp($a['subject_code'], $b['subject_code']));

        return $groups;
    }

    /**
     * Los grupos del horario del SII del periodo, con la misma forma que los de Nexus y sin carga publicada.
     *
     * @return list<array<string, mixed>>
     */
    private function siiGroups(Teacher $teacher, Term $term): array
    {
        return array_map(fn (array $group): array => [
            'id' => $group['id'],
            'code' => $group['code'],
            'subject_code' => $group['subject_code'],
            'subject_name' => $group['subject_name'],
            'campus_code' => $group['campus_code'],
            'campus_name' => $group['campus_name'],
            'department' => $group['department'],
            'hours' => $group['hours'],
            'counts_teacher_hours' => $group['counts_teacher_hours'],
            'schedule' => SessionLabels::lines(array_map(fn (array $s): array => [$s['day'], $s['starts_at'], $s['ends_at']], $group['sessions'])),
            'sessions' => array_map(fn (array $s): array => self::session($s['day'], $s['starts_at'], $s['ends_at'], $s['room'], 'class'), $group['sessions']),
            'published' => null,
        ], $this->sii->forTeacher($teacher->rfc, $term->code));
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromLive(Group $group): array
    {
        return [
            'id' => $group->id,
            'code' => $group->code,
            'subject_code' => $group->subject->code,
            'subject_name' => $group->subject->name,
            'campus_code' => $group->campus->code,
            'campus_name' => $group->campus->name,
            'department' => (string) $group->department?->name,
            'hours' => $group->subject->teacher_hours,
            'counts_teacher_hours' => (bool) $group->subject->counts_teacher_hours,
            'schedule' => SessionLabels::schedule($group->sessions),
            'sessions' => $group->sessions
                ->map(fn (GroupSession $session): array => self::session($session->day, (string) $session->starts_at, (string) $session->ends_at, $session->room?->code, $session->kind->value))
                ->values()
                ->all(),
            'published' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromPublished(PublishedGroup $group, ?Campus $campus, string $department, bool $countsTeacherHours = true): array
    {
        return [
            'id' => $group->id,
            'code' => $group->code,
            'subject_code' => $group->subject['code'],
            'subject_name' => $group->subject['name'],
            'campus_code' => $campus?->code ?? ($group->campus['code'] ?? ''),
            'campus_name' => $campus?->name ?? ($group->campus['name'] ?? ''),
            'department' => $department,
            'hours' => SubjectTeacherHours::of((int) $group->subject['theory_hours'], (int) $group->subject['practice_hours'], $countsTeacherHours),
            'counts_teacher_hours' => $countsTeacherHours,
            'schedule' => SessionLabels::published($group->sessions),
            'sessions' => array_map(fn (PublishedSession $session): array => self::session($session->day, $session->startsAt, $session->endsAt, $session->room['code'] ?? null, $session->kind), $group->sessions),
            'published' => ['version' => $group->version, 'at' => $group->publishedAt],
        ];
    }

    /**
     * @return array{day: int, start: int, end: int, time: string, room: ?string, kind: string}
     */
    private static function session(int $day, string $startsAt, string $endsAt, ?string $room, string $kind): array
    {
        return [
            'day' => $day,
            'start' => Minutes::of($startsAt),
            'end' => Minutes::of($endsAt),
            'time' => SessionLabels::span($startsAt, $endsAt),
            'room' => $room,
            'kind' => $kind,
        ];
    }

    /**
     * La cuenta de horas con las asignadas que se ven: base, frente a grupo con su banda, externas y límite de ahora
     * (PL-03: con plaza administrativa no hay banda, solo el tope duro de 42 h, HC-22; HC-08, que desde la tarea 1.50 se mide contra las horas frente a grupo: el límite es el tope de su banda o lo que
     * autorizó su casilla, SEL-12). `over_band` y `accepted` dicen si pasa su banda y si su casilla lo cubre.
     *
     * @return array{no_plaza: bool, extra: int, extra_state: string, extra_label: ?string, base: int, teaching: int, band_from: int, band_to: int, band_limit: int, honorarios_cap: ?int, limit: int, tolerance: int, external: int, assigned: int, remaining: int, administrative: ?int, administrative_cap: ?int, over_band: bool, accepted: bool, sources: list<array{source: string, hours: int}>}
     */
    private function hours(Teacher $teacher, Term $term, ScheduleState $state, int $assigned): array
    {
        // La foto solo trae a los docentes con grupos activos: sin ellos, se completa para mostrar base y externas.
        if ($state->teacher($teacher->id) === null) {
            $state = $state->withTeachers($this->states->teachers($term, [$teacher->id]));
        }

        $limit = TeacherHourLimit::of($state, $teacher->id)
            ?? ['administrative_hours' => null, 'administrative_cap' => null, 'administrative_excess' => 0, 'base_hours' => 0, 'teaching_hours' => 0, 'band_from' => 0, 'band_to' => 0, 'band_limit' => 0, 'honorarios_cap' => null, 'external_hours' => 0, 'tolerance' => 0, 'limit' => 0, 'allowance_hours' => null];
        $total = $assigned + $limit['external_hours'];
        $administrative = $limit['administrative_hours'];
        // PL-03 y HC-22: con plaza administrativa no hay banda de ±2; pasar el tope de 42 h es el choque duro HC-22.
        $overBand = $administrative === null ? $total > $limit['band_limit'] : $total > $limit['limit'];

        // PL-04: las horas extra de ahora (de lo que ve) y su estado: por autorizar, autorizadas, no autorizadas o jefatura.
        $extra = $limit['extra_hours'] ?? 0;
        $extraStatus = $administrative === null && $extra > 0
            ? $this->extraHours->forTerm($term, [$teacher->id], [$teacher->id => $extra], [$teacher->id => $teacher->user_id])[$teacher->id]
            : ExtraHoursStatus::none();

        return [
            'no_plaza' => $administrative === null && ($limit['no_plaza'] ?? false),
            'extra' => $extra,
            'extra_state' => $extraStatus->state->value,
            'extra_label' => $extraStatus->hasExtra() ? $extraStatus->label() : null,
            'base' => $limit['base_hours'],
            'teaching' => $limit['teaching_hours'],
            'band_from' => $limit['band_from'],
            'band_to' => $limit['band_to'],
            'band_limit' => $limit['band_limit'],
            'honorarios_cap' => $limit['honorarios_cap'],
            'limit' => $limit['limit'],
            'tolerance' => $limit['tolerance'],
            'external' => $limit['external_hours'],
            'assigned' => $assigned,
            'remaining' => $limit['limit'] - $total,
            'administrative' => $administrative,
            'administrative_cap' => $limit['administrative_cap'],
            'over_band' => $overBand,
            'accepted' => $overBand && $administrative === null && $limit['allowance_hours'] !== null && $total <= $limit['allowance_hours'],
            'sources' => TeacherExternalLoad::query()->where('term_id', $term->id)->where('teacher_id', $teacher->id)
                ->orderBy('source')->get(['source', 'hours'])
                ->map(fn (TeacherExternalLoad $load): array => ['source' => (string) $load->source, 'hours' => (int) $load->hours])->all(),
        ];
    }
}
