<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
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

    public function mount(): void
    {
        $this->authorize('viewAny', RoleAssignment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(PeopleQuery $people, AssignmentLabels $labels): array
    {
        $this->authorize('viewAny', RoleAssignment::class);

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
            'roles' => array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->label()], Role::cases()),
        ];
    }

    public function updated(string $property): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        if (in_array($property, ['search', 'role'], true)) {
            $this->resetPage();
        }
    }
};
