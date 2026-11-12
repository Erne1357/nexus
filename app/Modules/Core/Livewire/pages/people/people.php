<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\AppointmentsQuery;
use App\Modules\Core\Queries\AssignmentLabels;
use App\Modules\Core\Queries\PeopleQuery;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lista de «Personas y puestos» (3.P7, maqueta C): todos los usuarios con sus puestos vigentes, con búsqueda por
 * número o nombre y filtro por puesto. Sin acción principal: nombrar y terminar se hacen en la ficha de la persona
 * (core::pages.person), a la que lleva «Ver puestos». Solo la ve el admin con ese perfil activo (RoleAssignmentPolicy);
 * el componente autoriza en mount(), al renderizar y en cada cambio, porque una petición puede llegar con una página
 * que abrió otra sesión.
 *
 * La pestaña «Nombramientos» (T13, U3) lista un renglón por nombramiento (AppointmentsQuery) con modalidad y campus
 * («Todos los campus» si no lo fija), y sus filtros: persona, puesto, modalidad, campus y vigencia.
 */
new #[Title('Personas y puestos')] class extends Component
{
    use WithPagination;

    /** Búsqueda por usuario o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** '' (todos) o el valor de un Role; se recuerda en la sesión del usuario. */
    #[Session]
    public string $role = '';

    /** `people` o `appointments`. */
    #[Session]
    public string $tab = 'people';

    public string $appSearch = '';

    public string $appRole = '';

    public string $appModality = '';

    /** '' (todos), `all` (los que valen en todos los campus) o el id de un campus. */
    public string $appCampus = '';

    /** `current` (vigentes hoy) o `all`. */
    public string $appCurrent = 'current';

    public function mount(): void
    {
        $this->authorize('viewAny', RoleAssignment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(PeopleQuery $people, AssignmentLabels $labels, AppointmentsQuery $appointments): array
    {
        $this->authorize('viewAny', RoleAssignment::class);

        if ($this->tab === 'appointments') {
            return $this->appointmentsData($people, $labels, $appointments);
        }

        $page = $people->forTable($this->search, Role::tryFrom($this->role));
        $described = $labels->describe($page->getCollection()->flatMap(fn (User $user) => $user->roleAssignments));

        $summaries = [];

        foreach ($page as $user) {
            $summaries[$user->id] = $user->roleAssignments->map(fn (RoleAssignment $assignment): string => $described[$assignment->getKey()]->summary)->all();
        }

        return [
            'people' => $page,
            'summaries' => $summaries,
            'headers' => [
                ['key' => 'username', 'label' => 'Usuario'],
                ['key' => 'name', 'label' => 'Persona', 'class' => 'min-w-36 min-[1200px]:min-w-52'],
                ['key' => 'roles', 'label' => 'Puestos vigentes', 'sortable' => false],
                ['key' => 'is_active', 'label' => 'Estado', 'sortable' => false],
            ],
            'rowDecoration' => ['text-neutral' => fn (User $user): bool => ! $user->is_active],
            'roles' => array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->listLabel()], Role::cases()),
        ];
    }

    public function showTab(string $tab): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        $this->tab = $tab === 'appointments' ? 'appointments' : 'people';
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentsData(PeopleQuery $people, AssignmentLabels $labels, AppointmentsQuery $appointments): array
    {
        $page = $appointments->forTable(
            $this->appSearch,
            Role::tryFrom($this->appRole),
            $this->appModality === '' ? null : $this->appModality,
            $this->appCampus === '' ? null : $this->appCampus,
            $this->appCurrent !== 'all',
        );
        $described = $labels->describe($page->getCollection());
        $options = $people->scopeOptions(Role::Coordinador, null);
        $rows = [];

        foreach ($page as $assignment) {
            $chips = collect($described[$assignment->getKey()]->chips);
            $day = today()->toDateString();
            $rows[$assignment->getKey()] = [
                'scope' => $chips->first(fn (array $chip): bool => in_array($chip['label'], ['Carrera', 'Depto.', 'Unidad'], true))['value'] ?? '—',
                'modality' => $chips->firstWhere('label', 'Modalidad')['value'] ?? 'Todas',
                'campus' => $chips->firstWhere('label', 'Campus')['value'] ?? '—',
                'state' => $assignment->valid_from->toDateString() > $day ? 'future' : ($assignment->valid_to !== null && $assignment->valid_to->toDateString() < $day ? 'ended' : 'current'),
            ];
        }

        return [
            'appointments' => $page,
            'rows' => $rows,
            'titles' => collect($described)->map(fn ($label) => $label->title)->all(),
            'roles' => array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->listLabel()], array_filter(Role::cases(), fn (Role $role): bool => $role !== Role::Docente && $role !== Role::Alumno)),
            'modalities' => $options['modalities'],
            'campuses' => [['id' => AppointmentsQuery::ALL_CAMPUSES, 'name' => 'Todos los campus'], ...$options['campuses']],
            'currentOptions' => [['id' => 'current', 'name' => 'Vigentes'], ['id' => 'all', 'name' => 'Todos']],
            'headers' => [
                ['key' => 'person', 'label' => 'Persona', 'sortable' => false, 'class' => 'min-w-36'],
                ['key' => 'role', 'label' => 'Puesto', 'sortable' => false],
                ['key' => 'scope', 'label' => 'Carrera o departamento', 'sortable' => false],
                ['key' => 'modality', 'label' => 'Modalidad', 'sortable' => false],
                ['key' => 'campus', 'label' => 'Campus', 'sortable' => false],
                ['key' => 'validity', 'label' => 'Vigencia', 'sortable' => false],
            ],
        ];
    }

    public function updated(string $property): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        if (in_array($property, ['appSearch', 'appRole', 'appModality', 'appCampus', 'appCurrent'], true)) {
            $this->resetPage('appointmentsPage');
        }

        if (in_array($property, ['search', 'role'], true)) {
            $this->resetPage();
        }
    }
};
