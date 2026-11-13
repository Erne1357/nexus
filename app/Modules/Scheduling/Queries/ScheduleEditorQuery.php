<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Support\WeekGrid;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Validation\Clock;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

/**
 * Lo que lee el editor de horarios (tarea 3.5; DESIGN.md, "Editor de horarios") y cómo lo arma para pintarlo: la
 * navegación (PlanSemestersQuery), el semestre (SemesterBoardQuery), dónde cabe el grupo elegido (GroupFitQuery), la
 * semana de un docente o de un salón (TeacherScheduleQuery, RoomScheduleQuery) y los cajones de una sesión y de un
 * grupo. La página y sus cajones solo la llaman; no leen la base.
 *
 * Es `scoped` (SchedulingServiceProvider): memoriza por petición las carreras visibles, el semestre y el encaje, y
 * comparte un validador entre el tablero y el encaje, así que nada se calcula dos veces en la misma petición.
 * Después de escribir, forget() los suelta.
 *
 * Quién ve un grupo en el editor (sees()): quien lo ve por GroupPolicy::view o quien ve una carrera donde el grupo
 * está, su dueña o una con cupo, porque el semestre muestra sus grupos aunque no los acomode
 * (reglas-de-negocio.md, "Editor de horarios").
 */
final class ScheduleEditorQuery
{
    /** Reglas que la base no deja guardar: la celda que las rompe no coloca. */
    public const array UNSAVEABLE = ['HC-01', 'HC-02'];

    /** Reglas de totales del grupo: se leen como aviso («faltan horas») aunque el validador las dé duras. */
    public const array TOTALS = ['HC-12'];

    /** @var array<string, Term|Campus> */
    private array $models = [];

    /** @var array<string, list<array{id: string, code: string, name: string}>> */
    private array $careers = [];

    /** @var array<string, array<string, mixed>> */
    private array $semesters = [];

    /** @var array<string, array<string, mixed>> */
    private array $fits = [];

    public function __construct(
        private readonly PlanSemestersQuery $navigation,
        private readonly ScheduleValidator $validator,
        private readonly LoadEditability $editability,
        private readonly TeacherScheduleQuery $teacherSchedules,
        private readonly RoomScheduleQuery $roomSchedules,
    ) {}

    public function term(string $id): Term
    {
        return $this->models["term|{$id}"] ??= KeyLookup::findOrFail(Term::query(), $id);
    }

    public function campus(string $id): Campus
    {
        return $this->models["campus|{$id}"] ??= KeyLookup::findOrFail(Campus::query(), $id);
    }

    /**
     * Un grupo sin cancelar del periodo y campus; el id llega del cliente.
     */
    public function groupOrFail(Term $term, Campus $campus, string $id): Group
    {
        return KeyLookup::findOrFail(Group::query()->active()->where('term_id', $term->id)->where('campus_id', $campus->id), $id);
    }

    /**
     * Una sesión de un grupo sin cancelar del periodo y campus, con su grupo y su salón; el id llega del cliente.
     */
    public function sessionOrFail(Term $term, Campus $campus, string $id): GroupSession
    {
        return KeyLookup::findOrFail(
            GroupSession::query()
                ->whereIn('group_id', Group::query()->active()->where('term_id', $term->id)->where('campus_id', $campus->id)->select('id'))
                ->with(['group', 'room' => fn (Relation $query) => $query->withTrashed()]),
            $id,
        );
    }

    /**
     * Las carreras que el actor ve en el editor (PlanSemestersQuery::careers), una vez por petición.
     *
     * @return list<array{id: string, code: string, name: string}>
     */
    public function careers(Actor $actor, Term $term, Campus $campus): array
    {
        return $this->careers[$actor->user()?->getKey().'|'.$term->id.'|'.$campus->id] ??= $this->navigation->careers($actor, $term, $campus);
    }

    public function sees(Actor $actor, Term $term, Campus $campus, Group $group): bool
    {
        return Gate::forUser($actor->user())->allows('view', $group) || $this->visibleCareerOf($actor, $term, $campus, $group) !== null;
    }

    /**
     * Dónde abrir un grupo que llega por `?grupo=`: su carrera dueña, retícula y semestre si el actor la ve; si no, la
     * carrera con cupo que sí ve, en la retícula (la vigente primero) y el semestre donde está su materia. Null si no ve
     * ninguna carrera donde está el grupo.
     *
     * @return array{career: string, plan: string, semester: string}|null
     */
    public function entryOf(Actor $actor, Term $term, Campus $campus, Group $group): ?array
    {
        $career = $this->visibleCareerOf($actor, $term, $campus, $group);

        if ($career === null) {
            return null;
        }

        if ($career === $group->owner_career_id) {
            return ['career' => $career, 'plan' => $group->study_plan_id, 'semester' => (string) $group->semester];
        }

        $placement = StudyPlanSubject::query()
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->where('study_plans.career_id', $career)
            ->where('study_plans.is_active', true)
            ->where('study_plan_subjects.subject_id', $group->subject_id)
            ->orderByDesc('study_plans.is_current')
            ->first(['study_plan_subjects.study_plan_id', 'study_plan_subjects.semester']);

        return ['career' => $career, 'plan' => (string) $placement?->study_plan_id, 'semester' => (string) $placement?->semester];
    }

    /**
     * Lo que pide colocar un bloque: la etiqueta del grupo, el tipo (teoría hasta cubrir las horas de teoría de la
     * materia y luego práctica, si la tiene; supuesto por confirmar), los minutos que ya tiene y las horas que pide.
     *
     * @return array{label: string, kind: SessionKind, minutes: int, required: int}
     */
    public function placement(Group $group): array
    {
        $group->load(['subject' => fn (Relation $query) => $query->withTrashed(), 'sessions']);
        $theory = $group->sessions->filter(fn (GroupSession $session): bool => $session->kind === SessionKind::Teoria)->sum('minutes');

        return [
            'label' => "{$group->subject->code} · {$group->code}",
            'kind' => $group->subject->practice_hours > 0 && $theory >= $group->subject->theory_hours * 60 ? SessionKind::Practica : SessionKind::Teoria,
            'minutes' => (int) $group->sessions->sum('minutes'),
            'required' => $group->subject->theory_hours + $group->subject->practice_hours,
        ];
    }

    /**
     * La carrera, la retícula y el semestre de la selección, y el semestre del tablero; una vez por petición.
     *
     * @param  array{career: string, plan: string, semester: string}  $selection
     * @return array{message: ?string, career: ?Career, plans: list<array<string, mixed>>, plan: ?StudyPlan, semesters: list<array<string, mixed>>, semester: int, board: ?array<string, mixed>}
     */
    public function semester(Actor $actor, Term $term, Campus $campus, array $selection): array
    {
        return $this->semesters[implode('|', [$actor->user()?->getKey(), $term->id, $campus->id, ...$selection])] ??= $this->resolveSemester($actor, $term, $campus, $selection);
    }

    /**
     * Todo lo que pinta la página: la navegación, la bandeja, los choques, la semana de la vista (con el encaje del
     * grupo elegido) y la lista del celular de cada día.
     *
     * @param  array{career: string, plan: string, semester: string, group: string, view: string, teacher: string, room: string}  $selection
     * @return array<string, mixed>
     */
    public function editor(Actor $actor, Term $term, Campus $campus, array $selection): array
    {
        $careers = $this->careers($actor, $term, $campus);
        $semester = $this->semester($actor, $term, $campus, ['career' => $selection['career'], 'plan' => $selection['plan'], 'semester' => $selection['semester']]);
        $base = ['careers' => $careers, ...$semester];

        if ($semester['board'] === null) {
            return $base;
        }

        $board = $semester['board'];
        $groups = array_column($board['groups'], null, 'id');
        $selected = $selection['view'] === 'semestre' ? ($groups[$selection['group']] ?? null) : null;
        $chains = array_flip(array_values(array_unique(array_map(fn (array $group): string => $group['subject']['id'], $board['groups']))));
        $teacherOptions = $this->teacherOptions($board['groups']);
        $roomOptions = $this->roomOptions($board['groups']);

        [$items, $bands, $person] = match ($selection['view']) {
            'docente' => $this->teacherWeek($groups, $chains, $campus, $term, $teacherOptions, $selection['teacher']),
            'salon' => $this->roomWeek($groups, $chains, $term, $roomOptions, $selection['room']),
            default => [$this->semesterWeek($board, $chains, $selected), [], null],
        };

        $grid = WeekGrid::of($this->profileOf($term, $board['groups'], $selected))->covering([...$items, ...array_merge(...array_values($bands))]);
        $laid = $grid->lay($items);
        $cells = $grid->cells($selected === null ? null : $this->fit($term, $campus, $selected['id']), self::UNSAVEABLE);
        $canPlace = $selected !== null && $selected['can']['editSessions'];
        $conflicts = $this->conflicts($board, $groups, $grid);
        $days = array_column($grid->days, 'day');

        return [
            ...$base,
            'groups' => $board['groups'],
            'subjects' => $this->bySubject($board['groups'], $chains),
            'conflicts' => $conflicts,
            'hardCount' => count(array_filter($conflicts, fn (array $conflict): bool => $conflict['hard'])),
            'softCount' => count(array_filter($conflicts, fn (array $conflict): bool => ! $conflict['hard'])),
            'placedHours' => array_sum(array_column($board['groups'], 'placed_hours')),
            'requiredHours' => array_sum(array_column($board['groups'], 'required_hours')),
            'pending' => array_values(array_filter($semester['plans'], fn (array $row): bool => $row['is_priority'] && $row['unscheduled'] > 0 && $row['id'] !== $semester['plan']->id)),
            'grid' => $grid,
            'laid' => $laid,
            'lanes' => $grid->lanes($laid),
            'bands' => $bands,
            'busy' => array_combine($days, array_map(fn (int $day): int|float => $grid->busyHours($items, $day), $days)),
            'selected' => $selected,
            'selectedChain' => $selected === null ? null : $chains[$selected['subject']['id']],
            'editReason' => $selected === null ? null : $this->editReason($selected, $semester['career'], $campus),
            'cells' => $cells,
            'canPlace' => $canPlace,
            'tabStop' => $grid->tabStop($laid, $cells, $canPlace),
            'dayRows' => array_combine($days, array_map(fn (int $day): array => $grid->dayRows($laid[$day], $bands[$day] ?? [], $cells[$day] ?? null), $days)),
            'person' => $person,
            'teacherOptions' => $teacherOptions,
            'roomOptions' => $roomOptions,
        ];
    }

    /**
     * A quién y a qué salón lleva «Ver por» desde un grupo del semestre: su docente y el salón de su primera sesión que
     * tiene uno (por día y hora); null en cada uno si no tiene o el grupo no es del semestre.
     *
     * @param  array{career: string, plan: string, semester: string}  $selection
     * @return array{teacher: ?string, room: ?string}
     */
    public function lensOf(Actor $actor, Term $term, Campus $campus, array $selection, string $groupId): array
    {
        $board = $this->semester($actor, $term, $campus, $selection)['board'];
        $group = $board === null ? null : array_find($board['groups'], fn (array $row): bool => $row['id'] === $groupId);
        $session = $group === null ? null : array_find($group['sessions'], fn (array $row): bool => $row['room'] !== null);

        return ['teacher' => $group['teacher_id'] ?? null, 'room' => $session['room']['id'] ?? null];
    }

    /**
     * Si el grupo está en el semestre de la selección (lo que se puede elegir en la bandeja).
     *
     * @param  array{career: string, plan: string, semester: string}  $selection
     */
    public function inSemester(Actor $actor, Term $term, Campus $campus, array $selection, string $groupId): bool
    {
        $board = $this->semester($actor, $term, $campus, $selection)['board'];

        return $board !== null && in_array($groupId, array_column($board['groups'], 'id'), true);
    }

    /**
     * El cajón de una sesión, en datos simples que el cajón guarda entre peticiones: la sesión, su grupo, sus choques
     * duros y los avisos del grupo (HC-12 como aviso), por qué no se acomoda (si no se puede) y quién asigna su salón;
     * null si la sesión ya no existe.
     *
     * @return array<string, mixed>|null
     */
    public function sessionPanel(Actor $actor, Term $term, Campus $campus, string $sessionId): ?array
    {
        $withTrashed = fn (Relation $query) => $query->withTrashed();
        $session = KeyLookup::find(GroupSession::query()
            ->with(['group', 'group.subject' => $withTrashed, 'group.subject.department' => $withTrashed, 'group.teacher' => $withTrashed, 'group.ownerCareer' => $withTrashed, 'room' => $withTrashed]), $sessionId);

        if ($session === null || $session->group->term_id !== $term->id || $session->group->campus_id !== $campus->id) {
            return null;
        }

        $group = $session->group;
        $gate = Gate::forUser($actor->user());
        $violations = $this->validator->validateGroup($group)->violations();
        $mine = array_values(array_filter($violations, fn (Violation $violation): bool => ($violation->related['session_id'] ?? null) === $session->id));
        $groupWide = array_values(array_filter($violations, fn (Violation $violation): bool => ! isset($violation->related['session_id'])));
        $blocked = $this->editability->blockedReasons([$group])[$group->id];
        $grid = WeekGrid::of($this->validator->stateFor($group->term_id, $group->modality_id)->profile($group->modality_id));
        $department = $group->subject->department?->name ?? $group->subject->department?->code;
        $campusBound = SemesterBoardQuery::editsOnlyElsewhere($actor, $group);

        return [
            'id' => $session->id,
            'day' => $session->day,
            'starts_at' => Clock::of($session->starts_at),
            'ends_at' => Clock::of($session->ends_at),
            'kind' => $session->kind->value,
            'updated_at' => (string) $session->updated_at?->getTimestamp(),
            'room' => $session->room?->code,
            'group' => ['id' => $group->id, 'label' => "{$group->subject->code} · {$group->code}", 'subject' => $group->subject->name, 'teacher' => $group->teacher?->name],
            'hard' => array_map(self::plain(...), array_values(array_filter($mine, self::isHard(...)))),
            'soft' => array_map(self::plain(...), array_values(array_filter([...$mine, ...$groupWide], fn (Violation $violation): bool => ! self::isHard($violation)))),
            'editReason' => $blocked ?? ($gate->allows('editSessions', $group) ? null : $this->readOnlyReason($group->ownerCareer?->code ?? 'su carrera', $department, $campusBound ? $campus : null)),
            'roomReason' => $blocked === null && $gate->allows('assignRoom', $group) ? null : "Lo asigna el jefe de {$department}",
            'days' => array_map(fn (array $day): array => ['id' => (string) $day['day'], 'name' => $day['name']], $grid->days),
            'starts' => $this->timeOptions(array_column($grid->blocks, 'starts_at'), Clock::of($session->starts_at)),
            'ends' => $this->timeOptions(array_column($grid->blocks, 'ends_at'), Clock::of($session->ends_at)),
        ];
    }

    /**
     * El cajón de un grupo: su materia, quién imparte, capacidad, docente y sesiones, y sus horas.
     *
     * @return array<string, mixed>
     */
    public function groupPanel(Group $group, Campus $campus): array
    {
        $withTrashed = fn (Relation $query) => $query->withTrashed();
        $group->load(['modality' => $withTrashed, 'subject' => $withTrashed, 'subject.department' => $withTrashed, 'teacher' => $withTrashed, 'sessions' => fn (Relation $query) => $query->orderBy('day')->orderBy('starts_at'), 'sessions.room' => $withTrashed]);
        $short = array_column(WeekGrid::of($group->modality->profile)->days, 'short', 'day');

        return [
            'id' => $group->id,
            'code' => "{$group->subject->code} · {$group->code}",
            'subject' => $group->subject->name,
            'department' => trim(($group->subject->department?->code ?? '').' · '.($group->subject->department?->name ?? ''), ' ·'),
            'capacity' => $group->capacity,
            'teacher' => $group->teacher?->name,
            'placed' => WeekGrid::hours((int) $group->sessions->sum('minutes')),
            'required' => $group->subject->theory_hours + $group->subject->practice_hours,
            'sessions' => $group->sessions->map(fn (GroupSession $session): array => [
                'id' => $session->id,
                'day' => $short[$session->day] ?? (string) $session->day,
                'hours' => WeekGrid::range(Clock::of($session->starts_at), Clock::of($session->ends_at)),
                'kind' => $session->kind->label(),
                'room' => $session->room?->code,
            ])->all(),
        ];
    }

    /**
     * Los campus a los que el actor puede cambiar el grupo (M8): los activos, otros que el suyo, que ofrecen su carrera
     * dueña (`career_campus` activa, HC-13) y donde lo gestiona, como aquí (GroupPolicy::moveToCampus). Por clave.
     *
     * @return Collection<int, Campus>
     */
    public function campusOptions(Actor $actor, Group $group): Collection
    {
        $gate = Gate::forUser($actor->user());

        return Campus::query()
            ->where('is_active', true)
            ->whereKeyNot($group->campus_id)
            ->whereIn('id', CareerCampus::query()->where('career_id', $group->owner_career_id)->where('is_active', true)->select('campus_id'))
            ->orderBy('code')
            ->get()
            ->filter(fn (Campus $campus): bool => $gate->allows('moveToCampus', [$group, $campus]))
            ->values();
    }

    /**
     * Las reglas duras que el grupo rompe, por su firma (regla, de qué se trata y con qué otro grupo), sin las de
     * totales: para saber qué agregó colocar, aunque la violación no nombre la sesión (HC-08) o cambie su mensaje.
     *
     * @return array<string, true>
     */
    public function hardSignatures(Group $group): array
    {
        $signatures = [];

        foreach ($this->validator->validateGroup($group)->violations() as $violation) {
            if (self::isHard($violation)) {
                $signatures[self::signature($violation)] = true;
            }
        }

        return $signatures;
    }

    /**
     * La primera violación dura (sin las de totales) que no estaba antes, por su firma; null si no hay.
     *
     * @param  array<string, true>  $before
     * @param  list<Violation>  $after
     */
    public function firstNewHard(array $before, array $after): ?Violation
    {
        return array_find($after, fn (Violation $violation): bool => self::isHard($violation) && ! isset($before[self::signature($violation)]));
    }

    /**
     * Suelta lo memorizado después de escribir: el siguiente pintado lee lo guardado.
     */
    public function forget(): void
    {
        $this->semesters = [];
        $this->fits = [];
        $this->validator->forget();
    }

    /**
     * @param  array{career: string, plan: string, semester: string}  $selection
     * @return array<string, mixed>
     */
    private function resolveSemester(Actor $actor, Term $term, Campus $campus, array $selection): array
    {
        $careers = $this->careers($actor, $term, $campus);
        $empty = ['message' => null, 'career' => null, 'plans' => [], 'plan' => null, 'semesters' => [], 'semester' => 0, 'board' => null];

        if ($selection['career'] === '' && $careers === []) {
            return [...$empty, 'message' => 'Este campus no ofrece carreras cuyos horarios puedas ver.'];
        }

        $career = $this->navigation->careerOrFail($actor, $term, $campus, $selection['career'] !== '' ? $selection['career'] : $careers[0]['id']);
        $plans = $this->navigation->plans($career, $term, $campus);

        if ($selection['plan'] === '' && $plans === []) {
            return [...$empty, 'career' => $career, 'message' => "{$career->code} no tiene retículas activas."];
        }

        $plan = $this->navigation->planOrFail($career, $selection['plan'] !== '' ? $selection['plan'] : $plans[0]['id'], $term, $campus);
        $semesters = $this->navigation->semesters($plan, $term, $campus);
        $numbers = array_column($semesters, 'semester');
        $semester = in_array((int) $selection['semester'], $numbers, true) ? (int) $selection['semester']
            : (array_find($semesters, fn (array $row): bool => $row['groups'] > 0)['semester'] ?? ($numbers[0] ?? 1));
        $board = app(SemesterBoardQuery::class, ['validator' => $this->validator])->for($term, $campus, $career, $plan, $semester, $actor);

        return [...$empty, 'career' => $career, 'plans' => $plans, 'plan' => $plan, 'semesters' => $semesters, 'semester' => $semester, 'board' => $board];
    }

    /**
     * @return array<string, array{fits: bool, rules: list<string>, messages: list<string>}>
     */
    private function fit(Term $term, Campus $campus, string $groupId): array
    {
        return $this->fits[$groupId] ??= app(GroupFitQuery::class, ['validator' => $this->validator])->for($this->groupOrFail($term, $campus, $groupId));
    }

    /**
     * La carrera que el actor ve donde está el grupo: su dueña primero, luego las de sus cupos; null si ninguna.
     */
    private function visibleCareerOf(Actor $actor, Term $term, Campus $campus, Group $group): ?string
    {
        $visible = array_column($this->careers($actor, $term, $campus), 'id');

        if (in_array($group->owner_career_id, $visible, true)) {
            return $group->owner_career_id;
        }

        return array_find($group->quotas()->pluck('career_id')->all(), fn (string $career): bool => in_array($career, $visible, true));
    }

    /**
     * Las sesiones del semestre, con el color de su materia y las reglas duras que rompen. Con un grupo elegido que se
     * acomoda, las de los demás quedan bajo sus celdas (`covered`): ni se tocan ni las recorren las flechas.
     *
     * @param  array<string, mixed>  $board
     * @param  array<string, int>  $chains
     * @param  array<string, mixed>|null  $selected
     * @return list<array<string, mixed>>
     */
    private function semesterWeek(array $board, array $chains, ?array $selected): array
    {
        $items = [];

        foreach ($board['groups'] as $group) {
            foreach ($group['sessions'] as $session) {
                $items[] = [
                    ...$this->item($session, $group['id'], "{$group['subject']['code']} · {$group['code']}", $chains[$group['subject']['id']]),
                    'room' => $session['room']['code'] ?? null,
                    'roomless' => $session['room'] === null,
                    'rules' => $this->hardRules($board['violations']['sessions'][$session['id']] ?? []),
                    'mine' => $selected !== null && $selected['id'] === $group['id'],
                    'dim' => $selected !== null && $selected['id'] !== $group['id'],
                    'covered' => $selected !== null && $selected['can']['editSessions'] && $selected['id'] !== $group['id'],
                ];
            }
        }

        return $items;
    }

    /**
     * La semana de un docente de los grupos del semestre: sus sesiones en todos los campus y su disponibilidad.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, int>  $chains
     * @param  array<string, string>  $options
     * @return array{0: list<array<string, mixed>>, 1: array<int, list<array<string, mixed>>>, 2: array{id: string, name: string}|null}
     */
    private function teacherWeek(array $groups, array $chains, Campus $campus, Term $term, array $options, string $chosen): array
    {
        if ($options === []) {
            return [[], [], null];
        }

        $id = array_key_exists($chosen, $options) ? $chosen : array_key_first($options);
        $schedule = $this->teacherSchedules->for(KeyLookup::findOrFail(Teacher::query(), $id), $term);
        $items = [];

        foreach ($schedule['sessions'] as $session) {
            $own = $groups[$session['group_id']] ?? null;
            $items[] = [
                ...$this->item($session, $session['group_id'], "{$session['subject_code']} · {$session['group_code']}", $own === null ? null : $chains[$own['subject']['id']]),
                'room' => $session['room']['code'] ?? null,
                'roomless' => $own !== null && $session['room'] === null,
                'note' => $session['campus_id'] === $campus->id ? null : $session['campus_name'],
                'noteIcon' => 'o-map-pin',
                'open' => $own !== null,
                'external' => $own === null,
            ];
        }

        $bands = [];

        foreach ($schedule['availability'] as $range) {
            $bands[$range['day']][] = [
                'day' => $range['day'],
                'kind' => $range['kind'] === AvailabilityKind::Unavailable ? 'unavailable' : 'preferred',
                'label' => $range['kind']->label(),
                'hours' => WeekGrid::range($range['starts_at'], $range['ends_at']),
                'starts_at' => $range['starts_at'],
                'ends_at' => $range['ends_at'],
            ];
        }

        return [$items, $bands, ['id' => $id, 'name' => $options[$id]]];
    }

    /**
     * La semana de un salón de los que usan las sesiones del semestre: sus sesiones y sus bloqueos.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, int>  $chains
     * @param  array<string, string>  $options
     * @return array{0: list<array<string, mixed>>, 1: array<int, list<array<string, mixed>>>, 2: array{id: string, name: string}|null}
     */
    private function roomWeek(array $groups, array $chains, Term $term, array $options, string $chosen): array
    {
        if ($options === []) {
            return [[], [], null];
        }

        $id = array_key_exists($chosen, $options) ? $chosen : array_key_first($options);
        $schedule = $this->roomSchedules->for(KeyLookup::findOrFail(Room::query(), $id), $term);
        $items = [];

        foreach ($schedule['sessions'] as $session) {
            $own = $groups[$session['group_id']] ?? null;
            $items[] = [
                ...$this->item($session, $session['group_id'], "{$session['subject_code']} · {$session['group_code']}", $own === null ? null : $chains[$own['subject']['id']]),
                'note' => $session['teacher'] ?? 'Sin docente',
                'noteIcon' => 'o-user',
                'open' => $own !== null,
                'external' => $own === null,
            ];
        }

        $bands = [];

        foreach ($schedule['blocks'] as $block) {
            $bands[$block['day']][] = [
                'day' => $block['day'],
                'kind' => 'blocked',
                'label' => 'Bloqueado',
                'hours' => WeekGrid::range($block['starts_at'], $block['ends_at']),
                'reason' => $block['reason'],
                'starts_at' => $block['starts_at'],
                'ends_at' => $block['ends_at'],
            ];
        }

        return [$items, $bands, ['id' => $id, 'name' => $options[$id]]];
    }

    /**
     * Lo común de una sesión en la semana.
     *
     * @param  array{id: string, day: int, starts_at: string, ends_at: string, kind: SessionKind}  $session
     * @return array<string, mixed>
     */
    private function item(array $session, string $groupId, string $title, ?int $chain): array
    {
        return [
            'id' => $session['id'],
            'group_id' => $groupId,
            'day' => $session['day'],
            'starts_at' => $session['starts_at'],
            'ends_at' => $session['ends_at'],
            'hours' => WeekGrid::range($session['starts_at'], $session['ends_at']),
            'title' => $title,
            'kind' => $session['kind'],
            'chain' => $chain,
            'room' => null,
            'roomless' => false,
            'note' => null,
            'noteIcon' => null,
            'rules' => [],
            'open' => true,
            'external' => false,
            'mine' => false,
            'dim' => false,
            'covered' => false,
        ];
    }

    /**
     * Los grupos del semestre por materia, en el orden de la retícula.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, int>  $chains
     * @return list<array{subject: array<string, mixed>, chain: int, groups: list<array<string, mixed>>}>
     */
    private function bySubject(array $groups, array $chains): array
    {
        $subjects = [];

        foreach ($groups as $group) {
            $subjects[$group['subject']['id']] ??= ['subject' => $group['subject'], 'chain' => $chains[$group['subject']['id']], 'groups' => []];
            $subjects[$group['subject']['id']]['groups'][] = $group;
        }

        return array_values($subjects);
    }

    /**
     * «Choques del semestre»: una entrada por choque (el empalme de dos grupos del semestre se reporta en los dos y aquí
     * va una vez), primero los duros; HC-12 va como aviso. Cada una con el grupo del semestre al que pertenece y la
     * sesión de ese grupo a la que lleva «Ver», si es de una sesión.
     *
     * @param  array<string, mixed>  $board
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<array{rule: string, hard: bool, message: string, session: ?string, group: string, when: ?string}>
     */
    private function conflicts(array $board, array $groups, WeekGrid $grid): array
    {
        $sessions = [];

        foreach ($groups as $group) {
            foreach ($group['sessions'] as $session) {
                $sessions[$session['id']] = $session;
            }
        }

        $short = array_column($grid->days, 'short', 'day');
        $found = [];

        foreach ($board['violations']['groups'] as $groupId => $violations) {
            foreach ($violations as $violation) {
                $sessionId = $violation->related['session_id'] ?? null;
                $otherId = $violation->related['other_session_id'] ?? null;
                $pair = is_string($sessionId) && is_string($otherId) ? [$sessionId, $otherId] : null;

                if ($pair !== null) {
                    sort($pair);
                }

                $session = is_string($sessionId) ? ($sessions[$sessionId] ?? null) : null;
                $found[$pair === null ? $violation->key() : $violation->rule.'|'.implode('|', $pair)] ??= [
                    'rule' => $violation->rule,
                    'hard' => $violation->severity === ConflictSeverity::Hard && ! in_array($violation->rule, self::TOTALS, true),
                    'message' => $violation->message,
                    'session' => $session === null ? null : $session['id'],
                    'group' => (string) $groupId,
                    'when' => $session === null ? null : ($short[$session['day']] ?? $session['day']).' '.WeekGrid::range($session['starts_at'], $session['ends_at']),
                ];
            }
        }

        usort($found, fn (array $a, array $b): int => $b['hard'] <=> $a['hard']);

        return array_values($found);
    }

    /**
     * El perfil de la semana del editor: el de la modalidad del grupo elegido; sin grupo, el de la modalidad de los grupos
     * del semestre, o la ventana que cubre a todas si son de varias (ModalityProfile::covering); sin grupos, el de omisión.
     * Sale de las fotos del validador que el semestre ya leyó, sin otra consulta.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, mixed>|null  $selected
     */
    private function profileOf(Term $term, array $groups, ?array $selected): ModalityProfile
    {
        $ids = $selected === null ? array_values(array_unique(array_column($groups, 'modality_id'))) : [$selected['modality_id']];
        $profiles = array_map(fn (string $id): ModalityProfile => $this->validator->stateFor($term->id, $id)->profile($id), $ids);

        return count($profiles) === 1 ? $profiles[0] : ModalityProfile::covering($profiles);
    }

    /**
     * Por qué no se acomoda un grupo, una vez en la barra: lo que dice la Query (grupo cancelado, carga fuera de etapa)
     * o quién lo acomoda, con el campus si el usuario lo acomodaría en otro campus (MS6); null si se puede.
     *
     * @param  array<string, mixed>  $group
     */
    private function editReason(array $group, ?Career $career, Campus $campus): ?string
    {
        if ($group['can']['editSessions']) {
            return null;
        }

        if (($group['editBlockedReason'] ?? null) !== null) {
            return $group['editBlockedReason'];
        }

        $owner = $group['shared'] ? (collect($group['quotas'])->firstWhere('owner', true)['career_code'] ?? 'su carrera') : ($career?->code ?? 'su carrera');

        return $this->readOnlyReason($owner, $group['subject']['department_name'] ?? $group['subject']['department_code'] ?? null, $group['campus_bound'] ? $campus : null);
    }

    /**
     * Quién acomoda un grupo que el usuario solo ve. Con `$campus` (el del grupo), porque el usuario lo acomodaría si
     * fuera de su campus: lo dice, para que no parezca un permiso que le falta (MS6).
     */
    private function readOnlyReason(string $career, ?string $department, ?Campus $campus = null): string
    {
        $where = $campus === null ? '' : " en {$campus->name}";

        return "Solo lectura: acomodan sus sesiones el coordinador de {$career}{$where} y el jefe de ".($department ?? 'su departamento').'.';
    }

    /**
     * Los docentes de los grupos del semestre, por nombre.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, string>
     */
    private function teacherOptions(array $groups): array
    {
        $options = [];

        foreach ($groups as $group) {
            if ($group['teacher_id'] !== null) {
                $options[$group['teacher_id']] = (string) $group['teacher'];
            }
        }

        asort($options);

        return $options;
    }

    /**
     * Los salones que usan las sesiones del semestre, por clave.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, string>
     */
    private function roomOptions(array $groups): array
    {
        $options = [];

        foreach ($groups as $group) {
            foreach ($group['sessions'] as $session) {
                if ($session['room'] !== null) {
                    $options[$session['room']['id']] = $session['room']['code'];
                }
            }
        }

        asort($options);

        return $options;
    }

    /**
     * @param  list<string>  $times
     * @return list<array{id: string, name: string}>
     */
    private function timeOptions(array $times, string $current): array
    {
        $times = array_values(array_unique([...$times, $current]));
        sort($times);

        return array_map(fn (string $time): array => ['id' => $time, 'name' => WeekGrid::clock($time)], $times);
    }

    private static function isHard(Violation $violation): bool
    {
        return $violation->severity === ConflictSeverity::Hard && ! in_array($violation->rule, self::TOTALS, true);
    }

    /**
     * @return array{rule: string, message: string}
     */
    private static function plain(Violation $violation): array
    {
        return ['rule' => $violation->rule, 'message' => $violation->message];
    }

    private static function signature(Violation $violation): string
    {
        return implode('|', [$violation->rule, $violation->subjectType, $violation->subjectId, $violation->groupId ?? '', (string) ($violation->related['other_group_id'] ?? '')]);
    }

    /**
     * Las reglas duras de unas violaciones, sin las de totales.
     *
     * @param  list<Violation>  $violations
     * @return list<string>
     */
    private function hardRules(array $violations): array
    {
        return array_values(array_unique(array_map(
            fn (Violation $violation): string => $violation->rule,
            array_filter($violations, fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard && ! in_array($violation->rule, self::TOTALS, true)),
        )));
    }
}
