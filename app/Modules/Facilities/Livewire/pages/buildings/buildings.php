<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithCampusFilter;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Actions\CreateBuilding;
use App\Modules\Facilities\Actions\UpdateBuilding;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Queries\BuildingQuery;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * CRUD de Edificios, con la forma de Campus: tabla con búsqueda y filtro de estado; alta y edición en un
 * modal; activar o desactivar desde la tabla. Los edificios no se borran. Cada edificio es de un campus; la
 * credencial ya no lo elige (#415): el listado muestra los de todos los campus del usuario, con su campus en una
 * columna y un filtro de campus con «Todos» (WithCampusFilter), y el alta pide el campus en el formulario, entre
 * los campus donde el usuario puede dar de alta. Lo administran el admin y la Subdirección del campus
 * (BuildingPolicy).
 *
 * El componente lee con BuildingQuery, autoriza con BuildingPolicy en mount() y en cada acción (una
 * petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y llama a
 * CreateBuilding o UpdateBuilding, que hacen cumplir las reglas. Los campos del formulario se llaman como
 * las columnas, así los errores de las Actions caen en su campo.
 */
new #[Title('Edificios')] class extends Component
{
    use Toast, WithCampusFilter, WithPagination;

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

    /** El campus del alta (su id): se pide en el formulario. */
    public string $campus_id = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Building::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(BuildingQuery $buildings, Actor $context): array
    {
        $shown = $this->campusFilter();
        $options = $this->campusOptions();

        return [
            'campusOptions' => $options,
            'shownCampus' => $shown,
            'creatableCampuses' => $this->creatableCampuses()->map(fn (Campus $campus): array => ['id' => $campus->id, 'name' => "{$campus->code} · {$campus->name}"])->all(),
            'buildings' => $buildings->forTable($context, $shown === null ? $options : collect([$shown]), $this->search, match ($this->status) {
                'active' => true,
                'inactive' => false,
                default => null,
            }),
            'canCreate' => $this->creatableCampuses()->isNotEmpty(),
            'statuses' => [['id' => 'active', 'name' => 'Activos'], ['id' => 'inactive', 'name' => 'Inactivos']],
            'headers' => [
                ['key' => 'code', 'label' => 'Clave'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-36 min-[1200px]:min-w-52'],
                ...($options->count() > 1 ? [['key' => 'campus', 'label' => 'Campus']] : []),
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

    public function create(): void
    {
        $creatable = $this->creatableCampuses();
        abort_if($creatable->isEmpty(), 403);

        $this->resetValidation();
        $this->reset('editingId', 'code', 'name');
        $this->campus_id = ($this->campusFilter() ?? ($creatable->count() === 1 ? $creatable->first() : null))?->id ?? '';
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

    public function save(CreateBuilding $createBuilding, UpdateBuilding $updateBuilding, BuildingQuery $buildings): void
    {
        if ($this->editingId === null) {
            $creatable = $this->creatableCampuses();
            abort_if($creatable->isEmpty(), 403);
            $data = $this->validatedBuilding($creatable);
            $campus = $creatable->firstWhere('id', $data['campus_id']);
            $this->authorize('create', [Building::class, $campus]);
            $building = $createBuilding->handle($campus, ['code' => $data['code'], 'name' => $data['name']]);
            $this->success('Edificio creado', $this->describe($building));
        } else {
            $building = $buildings->findOrFail($this->editingId);
            $this->authorize('update', $building);
            $building = $updateBuilding->handle($building, Arr::only($this->validatedBuilding(), ['code', 'name']));
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
        return ['code' => 'clave', 'name' => 'nombre', 'campus_id' => 'campus'];
    }

    /**
     * Los campus donde el usuario puede dar de alta un edificio (BuildingPolicy::create): los del formulario.
     *
     * @return Collection<int, Campus>
     */
    private function creatableCampuses(): Collection
    {
        return $this->campusOptions()->filter(fn (Campus $campus): bool => auth()->user()->can('create', [Building::class, $campus]))->values();
    }

    /**
     * Clave y nombre validados en forma y, en un alta, el campus elegido entre `$creatable`; un campus que no está
     * ahí (ajeno, inactivo, inexistente) es un error del campo, no un 403.
     *
     * @param  Collection<int, Campus>|null  $creatable
     * @return array{code: string, name: string, campus_id: string}
     */
    private function validatedBuilding(?Collection $creatable = null): array
    {
        return $this->validate([
            'campus_id' => $creatable === null ? ['nullable'] : ['required', Rule::in($creatable->pluck('id')->all())],
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
