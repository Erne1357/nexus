<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\Selection\FinishMyLoad;
use App\Modules\Staffing\Enums\RankingMark;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Queries\SelectionOrderQuery;
use App\Modules\Staffing\Queries\SelectionTimetableQuery;
use App\Modules\Staffing\Queries\TeacherOfferQuery;
use App\Modules\Staffing\Rules\LoadBand;
use App\Modules\Staffing\Support\PublishedStamps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Oferta del departamento (HC-17, tarea 3.10): lo que un docente ve de los departamentos donde está adscrito, con su
 * propio lugar y desglose en cada grupo (maqueta B «Fichas por semestre»). Lo ve quien tiene el permiso personal
 * `staffing.offer.view` (el puesto de docente) y una ficha de docente con su usuario; sin la ficha, un aviso. Los
 * filtros (materia en `?q=`, semestre y «Todos · Sin docente · Entre tus 3 primeros» en `?estado=`, cada uno con su
 * cifra) solo ocultan fichas en pantalla: la consulta trae todo y no hay forma de esconder un grupo.
 *
 * «Entre tus 3 primeros» son los grupos que no son suyos donde ocupa del lugar 1 al 3 sin una marca que lo baje al
 * bloque final (sin horas, choque, no disponible; reglas-de-negocio.md, «Oferta del departamento», supuesto por
 * confirmar): la cifra del filtro cuenta exactamente lo que muestra. Las marcas de cada ficha son las de la asignación
 * (RankingMark). El panel muestra solo el desglose del propio docente y, con la marca «choque», con cuál de sus grupos
 * y a qué hora choca (TeacherOfferQuery::clashesWith).
 *
 * Con la carga del departamento publicada, las fichas son las de su última versión publicada (P8) y el encabezado lo
 * dice en una línea discreta, «Publicado el 8 dic. 2026 · v2» (PublishedStamps); si la pestaña junta cargas de varios
 * campus, cada línea lleva su campus.
 *
 * **Orden de selección** (tarea 1.33, diseño 6.4 a 6.7; maqueta A «Franjas por hora» con el orden en un drawer, como la
 * B): con una selección viva (publicada, en curso o en ronda extra) que el usuario ve (SelectionPolicy::view: el docente
 * con renglón en la cola de su departamento, o la jefatura y el JPD con `staffing.selection.view`), la pantalla es el
 * horario de la selección y no la oferta de arriba. El periodo es el de la selección, no el de la credencial. Un
 * renglón por hora de 7:00 a 21:00 con los grupos como fichas (SelectionTimetableQuery), la barra del turno pegada
 * arriba con la cuenta regresiva (Alpine, desde `ends_at` del servidor), la cuenta de horas contra la base (SEL-05),
 * «Orden de selección» (SelectionOrderQuery, en un drawer) y «Terminar mi carga» (FinishMyLoad, en un modal con la
 * casilla fuera del margen y el aviso de cada grupo sin salón). Se refresca cada 5 s. Al tocar una ficha se abre el
 * drawer del grupo (`staffing::components.selection-group-drawer`), que toma, suelta o, para quien tiene
 * `staffing.teacher.assign` sobre el grupo, asigna a mano. El docente sale siempre del usuario de la sesión. Filtros:
 * materia en `?q=` y «Todos · Libres · Míos» en `?grupos=`. Con varias selecciones a la vista, `?depto=` elige.
 */
new #[Title('Oferta del departamento')] class extends Component
{
    use Toast;

    /** Valores de `?estado=`. */
    private const array STATES = ['todos', 'sin', 'top'];

    /** Valores de `?grupos=` en el horario de selección. */
    private const array PICK_FILTERS = ['todos', 'libres', 'mios'];

    /** Código del departamento de la pestaña; '' el primero. */
    #[Url(as: 'depto')]
    public string $department = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'semestre')]
    public string $semester = '';

    /** 'todos', 'sin' (sin docente) o 'top' (entre sus 3 primeros). */
    #[Url(as: 'estado')]
    public string $state = 'todos';

    /** En el horario de selección: 'todos', 'libres' o 'mios'. */
    #[Url(as: 'grupos')]
    public string $picks = 'todos';

    #[Locked]
    public ?string $groupId = null;

    public bool $showDrawer = false;

    /** El drawer «Orden de selección». */
    public bool $showOrder = false;

    /** El modal «Terminar mi carga» y su casilla de responsabilidad (SEL-05). */
    public bool $showFinish = false;

    public bool $acknowledged = false;

    /** La selección de esta petición, para no buscarla dos veces (no viaja al navegador). */
    private ?Selection $selectionMemo = null;

    private bool $selectionResolved = false;

    public function mount(Actor $context): void
    {
        abort_unless($context->allowsAny(StaffingPermission::OfferView) || $context->allowsAny(StaffingPermission::SelectionView), 403);

        $this->updatedState();
        $this->updatedPicks();
    }

    public function updatedState(): void
    {
        $this->state = in_array($this->state, self::STATES, true) ? $this->state : 'todos';
    }

    public function updatedPicks(): void
    {
        $this->picks = in_array($this->picks, self::PICK_FILTERS, true) ? $this->picks : 'todos';
    }

    public function updatedShowFinish(): void
    {
        $this->acknowledged = false;
        $this->resetValidation('acknowledged');
    }

    /**
     * El drawer del grupo tomó, soltó o asignó: se vuelve a pintar el horario sin esperar el refresco.
     */
    #[On('selection-changed')]
    public function selectionChanged(): void {}

    /**
     * «Terminar mi carga» (SEL-05): el docente de la sesión, en su turno, con la casilla si está fuera del margen.
     */
    public function finish(Actor $context, SelectionTimetableQuery $timetable, FinishMyLoad $action): void
    {
        $user = $context->user() ?? abort(403);
        $selection = $this->selection($context, $timetable) ?? abort(404);
        $this->authorize('pickOwn', $selection);
        $teacher = $timetable->activeTeacherOf($user) ?? abort(403);
        $this->resetValidation();

        try {
            $entry = $action->handle($selection, $teacher, $this->acknowledged, $user);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            $this->error('No se terminó tu carga', e(collect($e->errors())->flatten()->first()));

            return;
        }

        $this->showFinish = false;
        $this->acknowledged = false;
        $entry->status === SelectionEntryStatus::Done
            ? $this->success('Terminaste tu carga', "{$entry->closed_hours} h. Tus grupos quedan a tu nombre.")
            : $this->warning('Cerraste con menos horas', 'Vuelves a elegir en la ronda extra, en el mismo orden.');
    }

    public function open(string $id): void
    {
        $this->groupId = $id;
        $this->showDrawer = true;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'semester', 'state');
    }

    public function with(Actor $context, TeacherOfferQuery $query, SelectionTimetableQuery $timetable, SelectionOrderQuery $order): array
    {
        $selection = $this->selection($context, $timetable);

        if ($selection !== null) {
            return $this->selectionView($selection, $context, $timetable, $order);
        }

        $user = $context->user();
        abort_unless($user !== null && $context->allows(StaffingPermission::OfferView, Target::ownedBy((string) $user->getKey())), 403);

        $term = $context->loadTerm();
        $teacher = Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->first();

        if ($term === null || $teacher === null) {
            return ['selection' => null, 'term' => $term, 'teacher' => $teacher, 'offer' => null, 'departments' => [], 'current' => null, 'groups' => [], 'visible' => [], 'semesters' => [], 'counts' => [], 'mine' => 0, 'published' => [], 'selected' => null, 'clashes' => []];
        }

        $offer = $query->forTeacher($teacher, $term);
        $all = array_map(fn (array $group): array => $group + ['marks' => RankingMark::fromFlags($group['flags'])], $offer['groups']);
        $all = array_map(fn (array $group): array => $group + ['top' => self::isTopThree($group)], $all);
        $departments = $offer['departments'];
        $current = collect($departments)->firstWhere('code', $this->department) ?? ($departments[0] ?? null);
        $groups = $current === null ? [] : array_values(array_filter($all, fn (array $group): bool => $group['department_id'] === $current['id']));

        $needle = self::fold($this->search);
        $matching = array_values(array_filter($groups, fn (array $group): bool => ($needle === '' || str_contains(self::fold($group['subject_name'].' '.$group['subject_code']), $needle))
            && ($this->semester === '' || (string) $group['semester'] === $this->semester)));
        $byState = [
            'todos' => $matching,
            'sin' => array_values(array_filter($matching, fn (array $group): bool => $group['teacher_name'] === null)),
            'top' => array_values(array_filter($matching, fn (array $group): bool => $group['top'])),
        ];
        $selected = $this->groupId === null ? null : collect($all)->firstWhere('id', $this->groupId);

        return [
            'selection' => null,
            'term' => $term,
            'teacher' => $teacher,
            'offer' => $offer,
            'departments' => $departments,
            'current' => $current,
            'groups' => $groups,
            'visible' => $byState[$this->state],
            'semesters' => array_values(array_unique(array_column($groups, 'semester'))),
            'counts' => array_map(count(...), $byState),
            'mine' => count(array_filter($groups, fn (array $group): bool => $group['is_mine'])),
            'published' => PublishedStamps::of(array_map(fn (array $group): array => ['department' => $current['name'], 'campus' => $group['campus_code'], 'published' => $group['published']], $groups)),
            'selected' => $selected,
            'clashes' => $selected !== null && in_array(RankingMark::Clash, $selected['marks'], true) ? $query->clashesWith($teacher, $term, $selected['id']) : [],
        ];
    }

    /**
     * La selección viva que ve el usuario (SelectionPolicy::view), o null: primero una donde está en la cola; con
     * varias, la del departamento de `?depto=` y si no, la primera.
     */
    private function selection(Actor $context, SelectionTimetableQuery $timetable): ?Selection
    {
        if ($this->selectionResolved) {
            return $this->selectionMemo;
        }

        $this->selectionResolved = true;
        $user = $context->user();

        if ($user === null) {
            return null;
        }

        $visible = $timetable->live()->filter(fn (Selection $selection): bool => Gate::allows('view', $selection))->values();
        $teacher = $visible->isEmpty() ? null : $timetable->activeTeacherOf($user);
        [$own, $others] = $visible->partition(fn (Selection $selection): bool => $teacher !== null && $timetable->entryOf($selection, $teacher) !== null);
        $pool = $own->merge($others);

        return $this->selectionMemo = $pool->first(fn (Selection $selection): bool => $selection->department?->code === $this->department) ?? $pool->first();
    }

    /**
     * Lo que pinta el horario de selección.
     *
     * @return array<string, mixed>
     */
    private function selectionView(Selection $selection, Actor $context, SelectionTimetableQuery $timetable, SelectionOrderQuery $order): array
    {
        $user = $context->user() ?? abort(403);
        $now = CarbonImmutable::now();
        $teacher = $timetable->activeTeacherOf($user);
        $entry = $teacher === null ? null : $timetable->entryOf($selection, $teacher);
        $data = $timetable->forTeacher($selection, $entry === null ? null : $teacher, $now);
        $assignable = [];
        $canAssign = function (array $card) use (&$assignable): bool {
            $key = "{$card['modality_id']}|{$card['campus_id']}|{$card['department_id']}";

            return $assignable[$key] ??= Gate::allows('create', [GroupTeacherAssignment::class, new Group(['modality_id' => $card['modality_id'], 'campus_id' => $card['campus_id'], 'department_id' => $card['department_id']])]);
        };

        $needle = self::fold($this->search);
        $matches = fn (array $card): bool => $needle === '' || str_contains(self::fold("{$card['subject_name']} {$card['subject_code']} {$card['group_code']}"), $needle);
        $filters = [
            'todos' => fn (array $card): bool => true,
            'libres' => fn (array $card): bool => $card['teacher'] === null,
            'mios' => fn (array $card): bool => $card['mine'],
        ];
        $strips = array_map(fn (array $strip): array => $strip + [
            'visible' => array_values(array_filter($strip['groups'], fn (array $card): bool => $matches($card) && $filters[$this->picks]($card))),
        ], SelectionTimetableQuery::strips($data));
        $all = collect($strips)->flatMap(fn (array $strip): array => $strip['groups'])->filter($matches)->unique('group_id');
        $strips = array_map(fn (array $strip): array => ['visible' => array_map(fn (array $card): array => $card + ['can_assign' => $canAssign($card)], $strip['visible'])] + $strip, $strips);
        $turn = $data['turn'];

        return [
            'selection' => $selection,
            'teacher' => $entry === null ? null : $teacher,
            'data' => $data,
            'strips' => $strips,
            'pickCounts' => array_map(fn (Closure $filter): int => $all->filter($filter)->count(), $filters),
            'shown' => array_sum(array_map(fn (array $strip): int => count($strip['visible']), $strips)),
            'assigns' => collect($strips)->flatMap(fn (array $strip): array => $strip['visible'])->contains('can_assign', true),
            'bar' => $this->turnBar($selection, $entry !== null, $turn, $data, $now),
            'band' => $entry === null ? null : LoadBand::classify($data['my_hours'], $data['base_hours']),
            'order' => $this->showOrder ? $order->forSelection($selection, $now) : [],
        ];
    }

    /**
     * Lo que dice la barra del turno, ya con sus horas en texto. `kind`: `mine` (su turno), `other` (el de otro),
     * `published` (aún no empieza), `idle` (en curso sin nadie en turno), `done` (ya terminó), `extra` (cerró con menos
     * y espera la ronda extra) o `manager` (la jefatura sin renglón en la cola).
     *
     * @param  array<string, mixed>  $turn
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function turnBar(Selection $selection, bool $inQueue, array $turn, array $data, CarbonImmutable $now): array
    {
        $at = fn (?string $iso): ?CarbonImmutable => $iso === null ? null : CarbonImmutable::parse($iso)->setTimezone(config('app.timezone'));
        $extra = $selection->status === SelectionStatus::ExtraRound;
        $mySlot = $at($extra ? $turn['my_extra_slot'] : $turn['my_slot']);
        $mySlotEnds = $at($turn['my_slot_ends']);
        $endsAt = $at($turn['ends_at']);
        $left = $endsAt === null ? null : max(0, (int) ceil($now->diffInSeconds($endsAt, false)));

        $kind = match (true) {
            ! $inQueue => 'manager',
            $turn['is_mine'] => 'mine',
            $turn['entry_status'] === SelectionEntryStatus::Done->value || $turn['entry_status'] === SelectionEntryStatus::Unresolved->value => 'done',
            $turn['entry_status'] === SelectionEntryStatus::PendingExtra->value && ! $extra => 'extra',
            $selection->status === SelectionStatus::Published => 'published',
            $turn['current'] !== null => 'other',
            default => 'idle',
        };

        return [
            'kind' => $kind,
            'current' => $turn['current'],
            'until' => $endsAt?->format('G:i'),
            'ends_at' => $turn['ends_at'],
            'ends_ms' => $endsAt?->getTimestampMs(),
            'now_ms' => $now->getTimestampMs(),
            'left' => $left === null ? null : intdiv($left, 60).':'.str_pad((string) ($left % 60), 2, '0', STR_PAD_LEFT),
            'mine_day' => $mySlot === null ? null : $mySlot->isoFormat('dddd D'),
            'mine_time' => $mySlot === null ? null : $mySlot->format('G:i').($mySlotEnds === null ? '' : '–'.$mySlotEnds->format('G:i')),
            'mine_in' => $mySlot === null || $mySlot->lessThanOrEqualTo($now) ? null : self::minutesFrom($now, $mySlot),
            'starts' => $selection->starts_at?->setTimezone(config('app.timezone')),
            'published' => $selection->published_at?->setTimezone(config('app.timezone')),
            'next' => $turn['next'] === null ? null : ['name' => $turn['next']['name'], 'at' => $at($turn['next']['at'])],
            'position' => $turn['position'],
            'current_position' => $turn['current_position'],
            'total' => $turn['total'],
            'done' => $turn['done'],
            'extra' => $extra,
        ];
    }

    /**
     * «en 43 min», «en 2 h 10 min» o «en 3 días».
     */
    private static function minutesFrom(CarbonImmutable $now, CarbonImmutable $then): string
    {
        $minutes = (int) ceil($now->diffInSeconds($then) / 60);

        return match (true) {
            $minutes < 60 => "en {$minutes} min",
            $minutes < 24 * 60 => 'en '.intdiv($minutes, 60).' h'.($minutes % 60 === 0 ? '' : ' '.($minutes % 60).' min'),
            default => 'en '.(int) ceil($minutes / (24 * 60)).' días',
        };
    }

    /**
     * Si cuenta en «Entre tus 3 primeros»: no es suyo, ocupa del lugar 1 al 3 y no tiene una marca que lo baje.
     *
     * @param  array{is_mine: bool, rank: ?int, marks: list<RankingMark>}  $group
     */
    private static function isTopThree(array $group): bool
    {
        return ! $group['is_mine'] && $group['rank'] !== null && $group['rank'] <= 3 && $group['marks'] === [];
    }

    private static function fold(string $text): string
    {
        $ascii = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()', $text);

        return trim($ascii === false ? mb_strtolower($text) : $ascii);
    }
};
