<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Facilities\Actions\CreateBuilding;
use App\Modules\Facilities\Actions\UpdateBuilding;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Queries\BuildingQuery;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * CRUD de Edificios, con la forma de Campus: tabla con búsqueda y filtro de estado; alta y edición en un
 * modal; activar o desactivar desde la tabla. Los edificios no se borran. Es de un campus, el activo de la
 * credencial (ScopedContext::activeCampus()): el listado y el alta usan ese campus, el formulario no lleva
 * un select de campus y, sin uno elegido, la pantalla pide elegirlo. Lo administran el admin y la
 * Subdirección del campus (BuildingPolicy).
 *
 * El componente lee con BuildingQuery, autoriza con BuildingPolicy en mount() y en cada acción (una
 * petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y llama a
 * CreateBuilding o UpdateBuilding, que hacen cumplir las reglas. Los campos del formulario se llaman como
 * las columnas, así los errores de las Actions caen en su campo.
 */
new #[Title('Edificios')] class extends Component
{
    use Toast, WithPagination;

    /** Búsqueda por clave o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** '' (todos), 'active' o 'inactive'. */
    #[Session]
    public string $status = '';

    public bool $showModal = false;

    /** El edificio que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Building::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(BuildingQuery $buildings, ScopedContext $context): array
    {
        $campus = $context->activeCampus();

        return [
            'campus' => $campus,
            'buildings' => $campus === null ? null : $buildings->forTable($context, $campus, $this->search, match ($this->status) {
                'active' => true,
                'inactive' => false,
                default => null,
            }),
            'canCreate' => $campus !== null && auth()->user()->can('create', [Building::class, $campus]),
            'statuses' => [['id' => 'active', 'name' => 'Activos'], ['id' => 'inactive', 'name' => 'Inactivos']],
            'headers' => [
                ['key' => 'code', 'label' => 'Clave'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-36 min-[1200px]:min-w-52'],
                ['key' => 'rooms_count', 'label' => 'Salones', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'is_active', 'label' => 'Estado'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de un inactivo pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (Building $building): bool => ! $building->is_active],
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(ScopedContext $context): void
    {
        $this->authorize('create', [Building::class, $this->campus($context)]);

        $this->resetValidation();
        $this->reset('editingId', 'code', 'name');
        $this->showModal = true;
    }

    public function edit(string $id, BuildingQuery $buildings): void
    {
        $building = $buildings->findOrFail($id);
        $this->authorize('update', $building);

        $this->resetValidation();
        $this->editingId = $building->id;
        $this->code = $building->code;
        $this->name = $building->name;
        $this->showModal = true;
    }

    public function save(CreateBuilding $createBuilding, UpdateBuilding $updateBuilding, BuildingQuery $buildings, ScopedContext $context): void
    {
        if ($this->editingId === null) {
            $campus = $this->campus($context);
            $this->authorize('create', [Building::class, $campus]);
            $building = $createBuilding->handle($campus, $this->validatedBuilding());
            $this->success('Edificio creado', $this->describe($building));
        } else {
            $building = $buildings->findOrFail($this->editingId);
            $this->authorize('update', $building);
            $building = $updateBuilding->handle($building, $this->validatedBuilding());
            $this->success('Edificio actualizado', $this->describe($building));
        }

        $this->showModal = false;
    }

    public function toggleActive(string $id, BuildingQuery $buildings, UpdateBuilding $updateBuilding): void
    {
        $building = $buildings->findOrFail($id);
        $this->authorize('update', $building);

        $building = $updateBuilding->handle($building, ['is_active' => ! $building->is_active]);

        $this->success($building->is_active ? 'Edificio activado' : 'Edificio desactivado', $this->describe($building));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['code' => 'clave', 'name' => 'nombre'];
    }

    /**
     * El campus activo de la credencial; sin uno no hay dónde dar de alta (403).
     */
    private function campus(ScopedContext $context): Campus
    {
        return $context->activeCampus() ?? abort(403, 'Elige un campus en tu credencial.');
    }

    /**
     * @return array{code: string, name: string}
     */
    private function validatedBuilding(): array
    {
        return $this->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
        ]);
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Building $building): string
    {
        return e("{$building->code} · {$building->name}");
    }
};
