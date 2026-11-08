<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Faculty\Models\Teacher;
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
 * filtros (materia, semestre, «Sin docente», «Entre los 3 primeros») solo ocultan fichas en pantalla: la consulta trae
 * todo y no hay forma de esconder un grupo. El panel muestra solo el desglose del propio docente.
 */
new #[Title('Oferta del departamento')] class extends Component
{
    /** Código del departamento de la pestaña; '' el primero. */
    #[Url(as: 'depto')]
    public string $department = '';

    #[Url(as: 'materia')]
    public string $search = '';

    #[Url(as: 'semestre')]
    public string $semester = '';

    #[Url(as: 'sin')]
    public bool $withoutTeacher = false;

    #[Url(as: 'top')]
    public bool $topThree = false;

    #[Locked]
    public ?string $groupId = null;

    public bool $showDrawer = false;

    public function mount(Actor $context): void
    {
        abort_unless($context->allowsAny(StaffingPermission::OfferView), 403);
    }

    public function open(string $id): void
    {
        $this->groupId = $id;
        $this->showDrawer = true;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'semester', 'withoutTeacher', 'topThree');
    }

    public function with(Actor $context, TeacherOfferQuery $query): array
    {
        $user = $context->user();
        abort_unless($user !== null && $context->allows(StaffingPermission::OfferView, Target::ownedBy((string) $user->getKey())), 403);

        $term = $context->activeTerm();
        $teacher = Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->first();

        if ($term === null || $teacher === null) {
            return ['term' => $term, 'teacher' => $teacher, 'offer' => null, 'departments' => [], 'current' => null, 'groups' => [], 'visible' => [], 'semesters' => [], 'summary' => null, 'selected' => null];
        }

        $offer = $query->forTeacher($teacher, $term);
        $departments = $offer['departments'];
        $current = collect($departments)->firstWhere('code', $this->department) ?? ($departments[0] ?? null);
        $groups = $current === null ? [] : array_values(array_filter($offer['groups'], fn (array $group): bool => $group['department_id'] === $current['id']));

        $needle = self::fold($this->search);
        $visible = array_values(array_filter($groups, fn (array $group): bool => ($needle === '' || str_contains(self::fold($group['subject_name'].' '.$group['subject_code']), $needle))
            && ($this->semester === '' || (string) $group['semester'] === $this->semester)
            && (! $this->withoutTeacher || $group['teacher_name'] === null)
            && (! $this->topThree || ($group['rank'] !== null && $group['rank'] <= 3))));

        return [
            'term' => $term,
            'teacher' => $teacher,
            'offer' => $offer,
            'departments' => $departments,
            'current' => $current,
            'groups' => $groups,
            'visible' => $visible,
            'semesters' => array_values(array_unique(array_column($groups, 'semester'))),
            'summary' => [
                'mine' => count(array_filter($groups, fn (array $group): bool => $group['is_mine'])),
                'top' => count(array_filter($groups, fn (array $group): bool => ! $group['is_mine'] && $group['rank'] !== null && $group['rank'] <= 3)),
                'free' => count(array_filter($groups, fn (array $group): bool => $group['teacher_name'] === null)),
            ],
            'selected' => $this->groupId === null ? null : collect($offer['groups'])->firstWhere('id', $this->groupId),
        ];
    }

    private static function fold(string $text): string
    {
        $ascii = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()', $text);

        return trim($ascii === false ? mb_strtolower($text) : $ascii);
    }
};
