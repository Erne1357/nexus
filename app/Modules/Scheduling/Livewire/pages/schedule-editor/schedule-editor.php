<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Facilities\Queries\RoomQuery;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Queries\TeacherQuery;
use App\Modules\Scheduling\Actions\Sessions\PlaceGroupSession;
use App\Modules\Scheduling\Actions\Sessions\RemoveGroupSession;
use App\Modules\Scheduling\Actions\Sessions\UpdateGroupSession;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\GroupFitQuery;
use App\Modules\Scheduling\Queries\PlanSemestersQuery;
use App\Modules\Scheduling\Queries\RoomScheduleQuery;
use App\Modules\Scheduling\Queries\SemesterBoardQuery;
use App\Modules\Scheduling\Queries\TeacherScheduleQuery;
use App\Modules\Scheduling\Support\WeekGrid;
use App\Modules\Scheduling\Validation\Clock;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Editor de horarios (tarea 3.5; maqueta A «Semana con bandeja», decidida por el usuario el 7 de noviembre de 2026;
 * DESIGN.md, "Editor de horarios"; reglas-de-negocio.md, "Editor de horarios"). Se trabaja una carrera, una de sus
 * retículas y un semestre del periodo y campus activos (fijos al abrir la página, como la disponibilidad): a la
 * izquierda la bandeja de grupos por materia y los choques del semestre; a la derecha la semana. Elegir un grupo pinta
 * dónde cabe (GroupFitQuery) y tocar una celda coloca un bloque (PlaceGroupSession); una sesión se mueve, cambia de
 * tipo o se quita en su cajón (UpdateGroupSession, RemoveGroupSession) y el cajón del grupo reparte sus cupos
 * (`scheduling::components.group-quotas`). La misma semana se ve por docente (TeacherScheduleQuery) o por salón
 * (RoomScheduleQuery).
 *
 * Entra quien ve los horarios (SchedulingPermission::ScheduleView); las carreras son las de
 * PlanSemestersQuery::careers y lo que llega por la URL pasa por careerOrFail y planOrFail. Un grupo del semestre se ve
 * aunque no se pueda acomodar (solo lectura, con el motivo en la barra); cada método recarga el grupo o la sesión con
 * KeyLookup, dentro del periodo y campus, y vuelve a autorizar: ver (GroupPolicy::view o la carrera del semestre, que
 * muestra también los grupos que no acomoda) o editSessions para escribir.
 *
 * La URL guarda la vista: `?carrera=`, `?reticula=`, `?semestre=`, `?grupo=` (el elegido; al entrar abre su carrera,
 * retícula y semestre, como lo usan el tablero de choques y las solicitudes de cambio), `?vista=semestre|docente|salon`,
 * `?docente=`, `?salon=` y `?dia=` (el día de la lista en celular).
 */
new #[Title('Horarios')] class extends Component
{
    use Toast;

    private const array VIEWS = ['semestre', 'docente', 'salon'];

    /** Reglas que la base no deja guardar: la celda que las rompe no coloca. */
    private const array UNSAVEABLE = ['HC-01', 'HC-02'];

    /** Reglas de totales del grupo: se ven como aviso («faltan horas») aunque el validador las dé duras. */
    private const array TOTALS = ['HC-12'];

    #[Url(as: 'carrera')]
    public string $careerId = '';

    #[Url(as: 'reticula')]
    public string $planId = '';

    #[Url(as: 'semestre')]
    public string $semester = '';

    /** El grupo elegido; '' ninguno. */
    #[Url(as: 'grupo')]
    public string $groupId = '';

    #[Url(as: 'vista')]
    public string $view = 'semestre';

    #[Url(as: 'docente')]
    public string $teacherId = '';

    #[Url(as: 'salon')]
    public string $roomId = '';

    /** El día de la lista del celular; '' es el primero del perfil. */
    #[Url(as: 'dia')]
    public string $day = '';

    /** El periodo y el campus activos al abrir la página; null si faltaba alguno. */
    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $campusId = null;

    #[Locked]
    public ?string $message = null;

    public bool $showSession = false;

    #[Locked]
    public ?string $sessionId = null;

    public string $sessionDay = '';

    public string $sessionStart = '';

    public string $sessionEnd = '';

    public string $sessionKind = '';

    /** «Quitamos el salón X…» tras mover una sesión a una hora donde su salón estaba ocupado. */
    #[Locked]
    public ?string $roomNotice = null;

    public bool $showGroup = false;

    #[Locked]
    public ?string $drawerGroupId = null;

    protected ?Term $loadedTerm = null;

    protected ?Campus $loadedCampus = null;

    public function mount(Actor $context): void
    {
        abort_unless($context->allowsAny(SchedulingPermission::ScheduleView), 403);

        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'semestre';
        $campus = $context->activeCampus();
        $term = $context->activeTerm();

        if ($campus === null || $term === null) {
            $this->message = $campus === null ? 'Elige un campus en tu credencial para ver los horarios.' : 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        $this->campusId = $campus->id;
        $this->termId = $term->id;

        if ($this->groupId !== '') {
            $group = $this->groupOrFail($this->groupId);
            $this->authorize('view', $group);

            $this->careerId = $group->owner_career_id;
            $this->planId = $group->study_plan_id;
            $this->semester = (string) $group->semester;
            $this->view = 'semestre';
        }
    }

    public function updatedCareerId(): void
    {
        $this->reset('planId', 'semester', 'groupId', 'teacherId', 'roomId');
    }

    public function updatedSemester(): void
    {
        $this->reset('groupId', 'teacherId', 'roomId');
    }

    public function choosePlan(string $planId): void
    {
        $this->planId = $planId;
        $this->reset('semester', 'groupId', 'teacherId', 'roomId');
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, self::VIEWS, true) ? $view : 'semestre';
        $this->groupId = '';
    }

    /**
     * Elige un grupo para pintar dónde cabe; elegirlo otra vez desde la bandeja lo suelta (`$toggle`).
     */
    public function pick(string $groupId, bool $toggle = true): void
    {
        $group = $this->groupOrFail($groupId);
        $this->authorizeSight($group);

        $this->groupId = $toggle && $this->groupId === $group->id ? '' : $group->id;
        $this->view = 'semestre';
    }

    public function clearSelection(): void
    {
        $this->groupId = '';
    }

    /**
     * Coloca un bloque del grupo en un día y hora (se une con los contiguos del mismo tipo). Teoría hasta cubrir las
     * horas de teoría de la materia y luego práctica (supuesto por confirmar); el cajón de la sesión lo cambia.
     */
    public function place(string $groupId, int $day, string $startsAt, PlaceGroupSession $place): void
    {
        $group = $this->groupOrFail($groupId);
        $this->authorize('editSessions', $group);

        $grid = WeekGrid::of($this->campus()->profile);
        $block = collect($grid->blocks)->firstWhere('starts_at', $startsAt);

        if ($block === null || ! in_array($day, array_column($grid->days, 'day'), true)) {
            $this->error('No se colocó', e('Esa hora no está en el horario del campus.'));

            return;
        }

        $group->load(['subject' => fn (Relation $query) => $query->withTrashed(), 'sessions']);
        $label = "{$group->subject->code} · {$group->code}";

        try {
            $result = $place->handle($group, $day, $block['starts_at'], $block['ends_at'], $this->kindFor($group), auth()->user());
        } catch (ValidationException $exception) {
            $this->error('No se colocó', e($this->firstError($exception)));

            return;
        }

        $this->groupId = $group->id;
        $this->dispatch('editor-placed', id: $result->session->id);
        $hours = WeekGrid::hours((int) $group->sessions()->get()->sum('minutes'));
        $required = $group->subject->theory_hours + $group->subject->practice_hours;
        $clash = $this->hardOf($result->validation->violations(), $result->session->id);
        $where = Minutes::dayName($day).' '.WeekGrid::clock($block['starts_at']);

        if ($clash !== null) {
            $this->warning('Colocada con choque', e("{$label}: {$where} · {$clash->rule} · {$clash->message}"));
        } else {
            $this->success('Sesión colocada', e("{$label}: {$where} · {$hours} de {$required} h"));
        }
    }

    public function openSession(string $sessionId): void
    {
        $session = $this->sessionOrFail($sessionId);
        $this->authorizeSight($session->group);

        $this->sessionId = $session->id;
        $this->fillSession($session);
        $this->roomNotice = null;
        $this->resetErrorBag();
        $this->showGroup = false;
        $this->showSession = true;
    }

    public function saveSession(UpdateGroupSession $update): void
    {
        $session = $this->sessionOrFail((string) $this->sessionId);
        $this->authorize('editSessions', $session->group);

        $grid = WeekGrid::of($this->campus()->profile);
        $this->validate([
            'sessionDay' => ['required', Rule::in(array_map('strval', array_column($grid->days, 'day')))],
            'sessionStart' => ['required', Rule::in([...array_column($grid->blocks, 'starts_at'), Clock::of($session->starts_at)])],
            'sessionEnd' => ['required', Rule::in([...array_column($grid->blocks, 'ends_at'), Clock::of($session->ends_at)])],
            'sessionKind' => ['required', Rule::enum(SessionKind::class)],
        ], attributes: ['sessionDay' => 'día', 'sessionStart' => 'inicio', 'sessionEnd' => 'fin', 'sessionKind' => 'tipo']);

        $room = $session->room?->code;

        try {
            $result = $update->handle($session, (int) $this->sessionDay, $this->sessionStart, $this->sessionEnd, SessionKind::from($this->sessionKind), auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('session', $this->firstError($exception));
            $this->error('No se guardó', e($this->firstError($exception)));

            return;
        }

        $this->sessionId = $result->session->id;
        $this->fillSession($result->session);
        $this->roomNotice = $result->roomDropped ? "Quitamos el salón {$room}: estaba ocupado a esa hora." : null;
        $label = $this->sessionLabel($session->group, $result->session);

        if ($this->roomNotice !== null) {
            $this->warning('Sesión guardada sin salón', e("{$label}. {$this->roomNotice}"));
        } else {
            $this->success('Sesión guardada', e($label));
        }
    }

    public function removeSession(RemoveGroupSession $remove): void
    {
        $session = $this->sessionOrFail((string) $this->sessionId);
        $this->authorize('editSessions', $session->group);
        $label = $this->sessionLabel($session->group, $session);

        try {
            $remove->handle($session, auth()->user());
        } catch (ValidationException $exception) {
            $this->error('No se quitó', e($this->firstError($exception)));

            return;
        }

        $this->showSession = false;
        $this->sessionId = null;
        $this->success('Sesión quitada', e($label));
    }

    public function openGroup(string $groupId): void
    {
        $group = $this->groupOrFail($groupId);
        $this->authorizeSight($group);

        $this->drawerGroupId = $group->id;
        $this->showSession = false;
        $this->showGroup = true;
    }

    /**
     * «Ver» de un choque: lleva la vista a la sesión (en celular, a su día) y la marca un momento.
     */
    public function focusSession(string $sessionId): void
    {
        $session = $this->sessionOrFail($sessionId);
        $this->authorizeSight($session->group);

        $this->day = (string) $session->day;
        $this->dispatch('editor-focus', id: $session->id);
    }

    /**
     * El panel de cupos guardó: se vuelve a pintar con lo nuevo.
     */
    #[On('quotas-saved')]
    public function quotasSaved(): void {}

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, PlanSemestersQuery $navigation, ScheduleValidator $validator, TeacherScheduleQuery $teacherSchedules, RoomScheduleQuery $roomSchedules, TeacherQuery $teachers, RoomQuery $rooms): array
    {
        if ($this->termId === null) {
            return ['term' => null, 'careers' => [], 'career' => null, 'message' => $this->message, 'session' => null, 'drawerGroup' => null];
        }

        $term = $this->term();
        $campus = $this->campus();
        $careers = $navigation->careers($context, $term, $campus);
        $base = ['term' => $term, 'campus' => $campus, 'careers' => $careers, 'career' => null, 'message' => null, 'session' => null, 'drawerGroup' => null];

        if ($this->careerId === '' && $careers === []) {
            return [...$base, 'message' => 'Este campus no ofrece carreras cuyos horarios puedas ver.'];
        }

        $career = $navigation->careerOrFail($context, $term, $campus, $this->careerId !== '' ? $this->careerId : $careers[0]['id']);
        $plans = $navigation->plans($career, $term, $campus);

        if ($this->planId === '' && $plans === []) {
            return [...$base, 'career' => $career, 'message' => "{$career->code} no tiene retículas activas."];
        }

        $plan = $navigation->planOrFail($career, $this->planId !== '' ? $this->planId : $plans[0]['id']);
        $semesters = $navigation->semesters($plan, $term, $campus);
        $semester = $this->semesterOf($semesters);
        $this->semester = (string) $semester;
        $board = app(SemesterBoardQuery::class, ['validator' => $validator])->for($term, $campus, $career, $plan, $semester, $context);
        $groups = array_column($board['groups'], null, 'id');
        $selected = $this->view === 'semestre' ? ($groups[$this->groupId] ?? null) : null;
        $grid = WeekGrid::of($campus->profile);
        $chains = array_flip(array_values(array_unique(array_map(fn (array $group): string => $group['subject']['id'], $board['groups']))));

        [$items, $bands, $person] = match ($this->view) {
            'docente' => $this->teacherWeek($groups, $chains, $campus, $term, $teacherSchedules, $teachers),
            'salon' => $this->roomWeek($groups, $chains, $term, $roomSchedules, $rooms),
            default => [$this->semesterWeek($board, $chains, $selected), [], null],
        };

        $fit = $selected === null ? null : app(GroupFitQuery::class, ['validator' => $validator])->for($this->groupOrFail($selected['id']));
        $laid = $grid->lay($items);
        $conflicts = $this->conflicts($board, $groups, $grid);
        $mobileDay = in_array((int) $this->day, array_column($grid->days, 'day'), true) ? (int) $this->day : ($grid->days[0]['day'] ?? 1);
        $cells = $this->cells($grid, $fit);
        $canPlace = $selected !== null && $selected['can']['editSessions'];

        return [
            ...$base,
            'career' => $career,
            'plans' => $plans,
            'plan' => $plan,
            'semesters' => $semesters,
            'semester' => $semester,
            'groups' => $board['groups'],
            'subjects' => $this->bySubject($board['groups'], $chains),
            'violations' => $board['violations'],
            'conflicts' => $conflicts,
            'hardCount' => count(array_filter($conflicts, fn (array $conflict): bool => $conflict['hard'])),
            'softCount' => count(array_filter($conflicts, fn (array $conflict): bool => ! $conflict['hard'])),
            'placedHours' => array_sum(array_column($board['groups'], 'placed_hours')),
            'requiredHours' => array_sum(array_column($board['groups'], 'required_hours')),
            'pending' => array_values(array_filter($plans, fn (array $row): bool => $row['is_priority'] && $row['unscheduled'] > 0 && $row['id'] !== $plan->id)),
            'grid' => $grid,
            'laid' => $laid,
            'lanes' => $grid->lanes($laid),
            'bands' => $bands,
            'busy' => array_combine(array_column($grid->days, 'day'), array_map(fn (array $day): int|float => $grid->busyHours($items, $day['day']), $grid->days)),
            'selected' => $selected,
            'selectedChain' => $selected === null ? null : $chains[$selected['subject']['id']],
            'editReason' => $selected === null ? null : $this->editReason($selected, $career),
            'cells' => $cells,
            'canPlace' => $canPlace,
            'tabStop' => $this->tabStop($grid, $laid, $cells, $canPlace),
            'person' => $person,
            'teacherOptions' => $this->teacherOptions($board['groups']),
            'roomOptions' => $this->roomOptions($board['groups']),
            'mobileDay' => $mobileDay,
            'mobileRows' => $this->mobileRows($grid, $laid[$mobileDay] ?? [], $bands[$mobileDay] ?? [], $cells[$mobileDay] ?? null),
            'session' => $this->showSession ? $this->sessionPanel($board, $groups, $grid, $career) : null,
            'drawerGroup' => $this->showGroup ? ($groups[$this->drawerGroupId] ?? null) : null,
            'chainClasses' => self::chainClasses(),
            'fills' => self::fills(),
        ];
    }

    /**
     * Los rellenos de las celdas y bandas de la semana, con utilidades de los tokens (DESIGN.md, "Editor de
     * horarios"): cabe en `success/15` con borde punteado; choca en rayas `error`; no se puede, no disponible y
     * bloqueado en las rayas `neutral` de la disponibilidad; preferido en `success/15`.
     *
     * @return array{fits: string, clash: string, blocked: string, preferred: string}
     */
    public static function fills(): array
    {
        return [
            'fits' => 'border border-dashed border-success/55 bg-success/10 text-success',
            'clash' => 'bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-error)_18%,transparent)_0_3px,transparent_3px_8px)] text-error',
            'blocked' => 'bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-neutral)_9%,var(--color-base-100))_0_5px,color-mix(in_oklab,var(--color-neutral)_20%,var(--color-base-100))_5px_10px)] text-secondary-muted',
            'preferred' => 'bg-success/15 text-success',
        ];
    }

    /**
     * Las clases que ponen el color de una materia en `--chain` (los tokens de las cadenas de la retícula).
     *
     * @return list<string>
     */
    public static function chainClasses(): array
    {
        return [
            '[--chain:var(--color-chain-1)]', '[--chain:var(--color-chain-2)]', '[--chain:var(--color-chain-3)]',
            '[--chain:var(--color-chain-4)]', '[--chain:var(--color-chain-5)]', '[--chain:var(--color-chain-6)]',
            '[--chain:var(--color-chain-7)]', '[--chain:var(--color-chain-8)]', '[--chain:var(--color-chain-9)]',
            '[--chain:var(--color-chain-10)]', '[--chain:var(--color-chain-11)]', '[--chain:var(--color-chain-12)]',
        ];
    }

    /**
     * Las sesiones del semestre, con el color de su materia y las reglas duras que rompen.
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
     * @return array{0: list<array<string, mixed>>, 1: array<int, list<array<string, mixed>>>, 2: array{id: string, name: string}|null}
     */
    private function teacherWeek(array $groups, array $chains, Campus $campus, Term $term, TeacherScheduleQuery $schedules, TeacherQuery $teachers): array
    {
        $options = $this->teacherOptions(array_values($groups));

        if ($options === []) {
            return [[], [], null];
        }

        $id = array_key_exists($this->teacherId, $options) ? $this->teacherId : array_key_first($options);
        $this->teacherId = $id;
        $schedule = $schedules->for($teachers->findOrFail($id), $term);
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
     * @return array{0: list<array<string, mixed>>, 1: array<int, list<array<string, mixed>>>, 2: array{id: string, name: string}|null}
     */
    private function roomWeek(array $groups, array $chains, Term $term, RoomScheduleQuery $schedules, RoomQuery $rooms): array
    {
        $options = $this->roomOptions(array_values($groups));

        if ($options === []) {
            return [[], [], null];
        }

        $id = array_key_exists($this->roomId, $options) ? $this->roomId : array_key_first($options);
        $this->roomId = $id;
        $schedule = $schedules->for($rooms->findOrFail($id), $term);
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
     * va una vez), primero los duros; HC-12 va como aviso. Cada uno con la sesión a la que lleva «Ver», o el grupo.
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
                    'group' => (string) ($violation->groupId ?? $groupId),
                    'when' => $session === null ? null : ($short[$session['day']] ?? $session['day']).' '.WeekGrid::range($session['starts_at'], $session['ends_at']),
                ];
            }
        }

        usort($found, fn (array $a, array $b): int => $b['hard'] <=> $a['hard']);

        return array_values($found);
    }

    /**
     * Dónde cabe el grupo elegido, por día y bloque: `fits` (cabe), `clash` (se guarda marcada), `blocked` (HC-01 o
     * HC-02: la base no la deja guardar) u `own` (ya tiene sesión ahí); null sin grupo elegido.
     *
     * @param  array<string, array{fits: bool, rules: list<string>, messages: list<string>}>|null  $fit
     * @return array<int, list<array{key: string, starts_at: string, ends_at: string, state: string, rules: list<string>, messages: list<string>}>>|null
     */
    private function cells(WeekGrid $grid, ?array $fit): ?array
    {
        if ($fit === null) {
            return null;
        }

        $cells = [];

        foreach ($grid->days as $day) {
            foreach ($grid->blocks as $block) {
                $key = "{$day['day']}|{$block['starts_at']}";
                $cell = $fit[$key] ?? null;
                $state = match (true) {
                    $cell === null => 'own',
                    $cell['fits'] => 'fits',
                    array_intersect($cell['rules'], self::UNSAVEABLE) !== [] => 'blocked',
                    default => 'clash',
                };
                $cells[$day['day']][] = ['key' => $key, 'starts_at' => $block['starts_at'], 'ends_at' => $block['ends_at'], 'state' => $state, 'rules' => $cell['rules'] ?? [], 'messages' => $cell['messages'] ?? []];
            }
        }

        return $cells;
    }

    /**
     * La única parada de tabulación de la cuadrícula: la primera celda que coloca o la primera sesión que abre; las
     * flechas recorren el resto (`scheduleEditor`).
     *
     * @param  array<int, list<array<string, mixed>>>  $laid
     * @param  array<int, list<array<string, mixed>>>|null  $cells
     */
    private function tabStop(WeekGrid $grid, array $laid, ?array $cells, bool $canPlace): ?string
    {
        foreach ($grid->days as $day) {
            foreach ($cells[$day['day']] ?? [] as $cell) {
                if ($canPlace && in_array($cell['state'], ['fits', 'clash'], true)) {
                    return 'cell:'.$cell['key'];
                }
            }

            foreach ($laid[$day['day']] as $item) {
                if ($item['open']) {
                    return 'session:'.$item['id'];
                }
            }
        }

        return null;
    }

    /**
     * La lista del celular: un renglón por bloque del día, con las sesiones que empiezan en él; los bloques que una
     * sesión ya cubre no llevan renglón.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $bands
     * @param  list<array<string, mixed>>|null  $cells
     * @return list<array{block: array<string, string>, items: list<array<string, mixed>>, cell: ?array<string, mixed>, band: ?array<string, mixed>}>
     */
    private function mobileRows(WeekGrid $grid, array $items, array $bands, ?array $cells): array
    {
        $rows = [];

        foreach ($grid->blocks as $index => $block) {
            $starting = array_values(array_filter($items, fn (array $item): bool => (int) floor($item['top']) === $index));
            $covered = array_any($items, fn (array $item): bool => $item['top'] < $index && $index < $item['top'] + $item['span']);

            if ($starting === [] && $covered) {
                continue;
            }

            $minutes = Minutes::of($block['starts_at']);
            $rows[] = [
                'block' => $block,
                'items' => $starting,
                'cell' => $cells[$index] ?? null,
                'band' => collect($bands)->first(fn (array $band): bool => Minutes::of($band['starts_at']) <= $minutes && $minutes < Minutes::of($band['ends_at'])),
            ];
        }

        return $rows;
    }

    /**
     * El cajón de la sesión: la sesión y su grupo del semestre, sus choques y los avisos del grupo.
     *
     * @param  array<string, mixed>  $board
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, mixed>|null
     */
    private function sessionPanel(array $board, array $groups, WeekGrid $grid, Career $career): ?array
    {
        foreach ($groups as $group) {
            foreach ($group['sessions'] as $session) {
                if ($session['id'] !== $this->sessionId) {
                    continue;
                }

                $own = $board['violations']['sessions'][$session['id']] ?? [];
                $groupWide = array_values(array_filter($board['violations']['groups'][$group['id']] ?? [], fn (Violation $violation): bool => ! isset($violation->related['session_id'])));
                $department = $group['subject']['department_name'] ?? $group['subject']['department_code'];

                return [
                    'session' => $session,
                    'group' => $group,
                    'hard' => array_values(array_filter($own, fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard && ! in_array($violation->rule, self::TOTALS, true))),
                    'soft' => array_values(array_filter([...$own, ...$groupWide], fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Soft || in_array($violation->rule, self::TOTALS, true))),
                    'roomReason' => $group['can']['assignRoom'] ? null : "Lo asigna el jefe de {$department}",
                    'editReason' => $this->editReason($group, $career),
                    'days' => array_map(fn (array $day): array => ['id' => (string) $day['day'], 'name' => $day['name']], $grid->days),
                    'starts' => $this->timeOptions(array_column($grid->blocks, 'starts_at'), $session['starts_at']),
                    'ends' => $this->timeOptions(array_column($grid->blocks, 'ends_at'), $session['ends_at']),
                ];
            }
        }

        return null;
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

    /**
     * Por qué no se acomoda un grupo, una vez en la barra: lo que dice la Query (grupo cancelado, carga fuera de etapa)
     * o quién lo acomoda; null si se puede.
     *
     * @param  array<string, mixed>  $group
     */
    private function editReason(array $group, Career $career): ?string
    {
        if ($group['can']['editSessions']) {
            return null;
        }

        if (($group['editBlockedReason'] ?? null) !== null) {
            return $group['editBlockedReason'];
        }

        $owner = $group['shared'] ? (collect($group['quotas'])->firstWhere('owner', true)['career_code'] ?? 'su carrera') : $career->code;
        $department = $group['subject']['department_name'] ?? $group['subject']['department_code'] ?? 'su departamento';

        return "Solo lectura: acomodan sus sesiones el coordinador de {$owner} y el jefe de {$department}.";
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
     * El semestre de la URL si el plan lo tiene; si no, el primero con grupos, o el primero.
     *
     * @param  list<array{semester: int, groups: int, unscheduled: int}>  $semesters
     */
    private function semesterOf(array $semesters): int
    {
        $numbers = array_column($semesters, 'semester');

        if (in_array((int) $this->semester, $numbers, true)) {
            return (int) $this->semester;
        }

        return collect($semesters)->firstWhere(fn (array $row): bool => $row['groups'] > 0)['semester'] ?? ($numbers[0] ?? 1);
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

    /**
     * La primera violación dura (sin las de totales) de una sesión, o null.
     *
     * @param  list<Violation>  $violations
     */
    private function hardOf(array $violations, string $sessionId): ?Violation
    {
        return collect($violations)->first(fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard
            && ! in_array($violation->rule, self::TOTALS, true)
            && ($violation->related['session_id'] ?? null) === $sessionId);
    }

    /**
     * Teoría hasta cubrir las horas de teoría de la materia; luego práctica, si la tiene.
     */
    private function kindFor(Group $group): SessionKind
    {
        $theory = $group->sessions->filter(fn (GroupSession $session): bool => $session->kind === SessionKind::Teoria)->sum('minutes');

        return $group->subject->practice_hours > 0 && $theory >= $group->subject->theory_hours * 60 ? SessionKind::Practica : SessionKind::Teoria;
    }

    private function fillSession(GroupSession $session): void
    {
        $this->sessionDay = (string) $session->day;
        $this->sessionStart = Clock::of($session->starts_at);
        $this->sessionEnd = Clock::of($session->ends_at);
        $this->sessionKind = $session->kind->value;
    }

    private function sessionLabel(Group $group, GroupSession $session): string
    {
        $group->loadMissing(['subject' => fn (Relation $query) => $query->withTrashed()]);

        return "{$group->subject->code} · {$group->code}: ".Minutes::dayName($session->day).' '.WeekGrid::range(Clock::of($session->starts_at), Clock::of($session->ends_at));
    }

    private function firstError(ValidationException $exception): string
    {
        return (string) collect($exception->errors())->flatten()->first();
    }

    /**
     * Ver un grupo en el editor: quien lo ve por su policy o quien ve una carrera donde el grupo está (su dueña o una
     * con cupo), porque el semestre muestra sus grupos aunque no los acomode (reglas-de-negocio.md, "Editor de
     * horarios").
     */
    private function authorizeSight(Group $group): void
    {
        if (Gate::allows('view', $group)) {
            return;
        }

        $visible = array_column(app(PlanSemestersQuery::class)->careers(app(Actor::class), $this->term(), $this->campus()), 'id');
        $careers = [$group->owner_career_id, ...$group->quotas()->pluck('career_id')->all()];

        abort_if(array_intersect($careers, $visible) === [], 403);
    }

    private function groupOrFail(string $id): Group
    {
        abort_if($this->termId === null, 404);

        return KeyLookup::findOrFail(Group::query()->active()->where('term_id', $this->termId)->where('campus_id', $this->campusId), $id);
    }

    private function sessionOrFail(string $id): GroupSession
    {
        abort_if($this->termId === null, 404);

        return KeyLookup::findOrFail(
            GroupSession::query()
                ->whereIn('group_id', Group::query()->active()->where('term_id', $this->termId)->where('campus_id', $this->campusId)->select('id'))
                ->with(['group', 'room' => fn (Relation $query) => $query->withTrashed()]),
            $id,
        );
    }

    private function term(): Term
    {
        return $this->loadedTerm ??= KeyLookup::findOrFail(Term::query(), (string) $this->termId);
    }

    private function campus(): Campus
    {
        return $this->loadedCampus ??= KeyLookup::findOrFail(Campus::query(), (string) $this->campusId);
    }
};
