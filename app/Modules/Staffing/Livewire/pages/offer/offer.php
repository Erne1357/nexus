<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\RankingMark;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Queries\TeacherOfferQuery;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

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
 */
new #[Title('Oferta del departamento')] class extends Component
{
    /** Valores de `?estado=`. */
    private const array STATES = ['todos', 'sin', 'top'];

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

    #[Locked]
    public ?string $groupId = null;

    public bool $showDrawer = false;

    public function mount(Actor $context): void
    {
        abort_unless($context->allowsAny(StaffingPermission::OfferView), 403);

        $this->updatedState();
    }

    public function updatedState(): void
    {
        $this->state = in_array($this->state, self::STATES, true) ? $this->state : 'todos';
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

    public function with(Actor $context, TeacherOfferQuery $query): array
    {
        $user = $context->user();
        abort_unless($user !== null && $context->allows(StaffingPermission::OfferView, Target::ownedBy((string) $user->getKey())), 403);

        $term = $context->activeTerm();
        $teacher = Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->first();

        if ($term === null || $teacher === null) {
            return ['term' => $term, 'teacher' => $teacher, 'offer' => null, 'departments' => [], 'current' => null, 'groups' => [], 'visible' => [], 'semesters' => [], 'counts' => [], 'mine' => 0, 'selected' => null, 'clashes' => []];
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
            'selected' => $selected,
            'clashes' => $selected !== null && in_array(RankingMark::Clash, $selected['marks'], true) ? $query->clashesWith($teacher, $term, $selected['id']) : [],
        ];
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
