<?php

use App\Modules\Academic\Queries\DepartmentQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithCampusFilter;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Actions\AddRoomBlock;
use App\Modules\Facilities\Actions\CreateRoom;
use App\Modules\Facilities\Actions\RemoveRoomBlock;
use App\Modules\Facilities\Actions\UpdateRoom;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Queries\BuildingQuery;
use App\Modules\Facilities\Queries\RoomQuery;
use App\Modules\Facilities\Rules\RoomRules;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * CRUD de Salones, con la forma de Campus: tabla con búsqueda y filtros por edificio, tipo, departamento
 * dueño y estado; alta y edición en un modal; activar o desactivar desde la tabla. Los salones no se
 * borran. «Bloqueos» abre un panel lateral (x-drawer) con los bloqueos del salón y el formulario para
 * agregar otro. Cada salón es de un campus; la credencial ya no lo elige (#415): el listado muestra los salones
 * de todos los campus del usuario cuyo perfil usa salones (`usesRooms`), con una columna «Campus» y el filtro de
 * campus con «Todos» (WithCampusFilter); el alta toma el campus del edificio elegido, y el select de edificios va
 * agrupado por campus. Lo administran el admin y la Subdirección del campus (RoomPolicy).
 *
 * El componente lee con RoomQuery, autoriza con RoomPolicy en mount() y en cada acción (una petición puede
 * llegar con una página que abrió otra sesión), valida la forma de los datos y llama a CreateRoom,
 * UpdateRoom, AddRoomBlock o RemoveRoomBlock, que hacen cumplir las reglas. Los campos del formulario se
 * llaman como las columnas, así los errores de las Actions caen en su campo.
 */
new #[Title('Salones')] class extends Component
{
    use Toast, WithCampusFilter, WithPagination;

    /** Búsqueda por clave o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** Id del edificio; '' son todos. */
    #[Session]
    public string $buildingFilter = '';

    /** Valor de RoomType; '' son todos. */
    #[Session]
    public string $typeFilter = '';

    /** Id del departamento dueño o RoomQuery::WITHOUT_OWNER; '' son todos. */
    #[Session]
    public string $ownerFilter = '';

    /** '' (todos), 'active' o 'inactive'. */
    #[Session]
    public string $status = '';

    public bool $showModal = false;

    /** El salón que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $building_id = '';

    public string $code = '';

    public string $name = '';

    /** Valor de RoomType. */
    public string $type = '';

    public string $capacity = '';

    public string $department_id = '';

    /** Las características en una línea, separadas por comas. */
    public string $features = '';

    /**
     * Los edificios del campus del salón que se edita (edit() los lee una vez); el navegador no los puede cambiar.
     *
     * @var list<array{id: string, name: string, campus: string}>
     */
    #[Locked]
    public array $editingBuildings = [];

    /** Si el panel de bloqueos está abierto. */
    public bool $showDrawer = false;

    /** El salón cuyos bloqueos muestra el panel. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $blocksRoomId = null;

    public string $day = '';

    public string $starts_at = '';

    public string $ends_at = '';

    /** Id del periodo; '' son todos. */
    public string $term_id = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Room::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(RoomQuery $rooms, BuildingQuery $buildingQuery, DepartmentQuery $departmentQuery, Actor $context): array
    {
        $options = $this->campusOptions();
        $shown = $this->campusFilter();
        $roomCampuses = ($shown === null ? $options : collect([$shown]))->filter(fn (Campus $campus): bool => $campus->profile->usesRooms)->values();
        $buildings = $buildingQuery->options($roomCampuses);
        $buildingGroups = $this->buildingGroups($buildingQuery, $options);
        $departments = $departmentQuery->options();
        $types = $rooms->typeOptions();
        $ownerFilters = [['id' => RoomQuery::WITHOUT_OWNER, 'name' => 'Sin dueño'], ...$departments];
        $this->forgetFiltersOutside($buildings, $types, $ownerFilters);

        $blocksRoom = $this->openBlocksRoom($rooms);

        return [
            'campusOptions' => $options,
            'shownCampus' => $shown,
            'usesRooms' => $roomCampuses->isNotEmpty(),
            'rooms' => $roomCampuses->isEmpty() ? null : $rooms->forTable(
                $context,
                $roomCampuses,
                $this->search,
                $this->buildingFilter === '' ? null : $this->buildingFilter,
                $this->typeFilter === '' ? null : $this->typeFilter,
                $this->ownerFilter === '' ? null : $this->ownerFilter,
                match ($this->status) {
                    'active' => true,
                    'inactive' => false,
                    default => null,
                },
            ),
            'canCreate' => $this->creatableCampuses()->isNotEmpty(),
            'formBuildings' => collect($this->formBuildings($buildingQuery))->groupBy('campus')->all(),
            'ownerOptions' => $rooms->ownerOptions($this->department_id === '' ? null : $this->department_id),
            'buildingGroups' => $buildingGroups,
            'buildingScope' => $this->buildingFilter !== '' ? $this->buildingFilter : ($shown === null ? '' : "campus:{$shown->code}"),
            'buildings' => $options->count() > 1 ? array_map(fn (array $building): array => [...$building, 'name' => "{$building['campus']} · {$building['name']}"], $buildings) : $buildings,
            'types' => $types,
            'departments' => $departments,
            'ownerFilters' => $ownerFilters,
            'statuses' => [['id' => 'active', 'name' => 'Activos'], ['id' => 'inactive', 'name' => 'Inactivos']],
            // El edificio y el dueño son las columnas descriptivas: se ocultan debajo de 1024 px.
            'headers' => [
                ['key' => 'code', 'label' => 'Clave'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-32 min-[1200px]:min-w-44'],
                ...($options->count() > 1 ? [['key' => 'campus', 'label' => 'Campus', 'class' => 'max-[1023px]:hidden']] : []),
                ['key' => 'building', 'label' => 'Edificio', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'type', 'label' => 'Tipo', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'capacity', 'label' => 'Capacidad'],
                ['key' => 'department', 'label' => 'Dueño', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'blocks_count', 'label' => 'Bloqueos', 'class' => 'max-[1535px]:hidden'],
                ['key' => 'is_active', 'label' => 'Estado'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de un inactivo pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (Room $room): bool => ! $room->is_active],
            'blocksRoom' => $blocksRoom,
            'blocks' => $blocksRoom === null ? collect() : $rooms->blocksOf($blocksRoom),
            'dayOptions' => $blocksRoom === null ? [] : array_map(
                fn (int $day): array => ['id' => $day, 'name' => ucfirst(RoomRules::DAYS[$day])],
                $blocksRoom->campus->profile->days,
            ),
            'termOptions' => $blocksRoom === null ? [] : $rooms->termOptions(),
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'campus', 'buildingFilter', 'typeFilter', 'ownerFilter', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * El filtro «Edificio» con más de un campus: un solo select con un grupo por campus. «Todo C2» (`campus:C2`) fija
     * el campus, un edificio fija el edificio y la opción vacía lo quita todo, así la barra no gana otro control.
     */
    public function setBuildingScope(string $value): void
    {
        $this->buildingFilter = '';
        $this->campus = null;

        if (str_starts_with($value, 'campus:')) {
            $code = substr($value, 7);
            $this->campus = $this->campusOptions()->contains('code', $code) ? $code : null;
        } else {
            $this->buildingFilter = $value;
        }

        $this->resetPage();
    }

    public function updatedShowDrawer(bool $open): void
    {
        if (! $open) {
            $this->blocksRoomId = null;
        }
    }

    public function create(): void
    {
        abort_if($this->creatableCampuses()->isEmpty(), 403);

        $this->resetValidation();
        $this->reset('editingId', 'editingBuildings', 'building_id', 'code', 'name', 'type', 'capacity', 'department_id', 'features');
        $this->showModal = true;
    }

    public function edit(string $id, RoomQuery $rooms, BuildingQuery $buildings): void
    {
        $room = $rooms->findOrFail($id);
        $this->authorize('update', $room);

        $this->resetValidation();
        $this->editingId = $room->id;
        $this->editingBuildings = $buildings->options(collect([$room->campus]));
        $this->building_id = $room->building_id;
        $this->code = $room->code;
        $this->name = $room->name ?? '';
        $this->type = $room->type->value;
        $this->capacity = (string) $room->capacity;
        $this->department_id = $room->department_id ?? '';
        $this->features = implode(', ', $room->features);
        $this->showModal = true;
    }

    public function save(CreateRoom $createRoom, UpdateRoom $updateRoom, RoomQuery $rooms, BuildingQuery $buildings): void
    {
        if ($this->editingId === null) {
            $creatable = $this->creatableCampuses();
            abort_if($creatable->isEmpty(), 403);
            $data = $this->validatedRoom();
            $campus = $buildings->findInCampuses($this->building_id, $creatable)?->campus
                ?? throw ValidationException::withMessages(['building_id' => 'El edificio no es de un campus donde puedas dar de alta salones.']);
            $this->authorize('create', [Room::class, $campus]);
            $room = $createRoom->handle($campus, $data);
            $this->success('Salón creado', $this->describe($room));
        } else {
            $room = $rooms->findOrFail($this->editingId);
            $this->authorize('update', $room);
            $room = $updateRoom->handle($room, $this->validatedRoom());
            $this->success('Salón actualizado', $this->describe($room));
        }

        $this->showModal = false;
    }

    public function toggleActive(string $id, RoomQuery $rooms, UpdateRoom $updateRoom): void
    {
        $room = $rooms->findOrFail($id);
        $this->authorize('update', $room);

        $room = $updateRoom->handle($room, ['is_active' => ! $room->is_active]);

        $this->success($room->is_active ? 'Salón activado' : 'Salón desactivado', $this->describe($room));
    }

    public function openBlocks(string $id, RoomQuery $rooms): void
    {
        $room = $rooms->findOrFail($id);
        $this->authorize('update', $room);

        $this->resetValidation();
        $this->reset('day', 'starts_at', 'ends_at', 'term_id', 'reason');
        $this->blocksRoomId = $room->id;
        $this->showDrawer = true;
    }

    public function addBlock(AddRoomBlock $addRoomBlock, RoomQuery $rooms): void
    {
        $room = $this->blocksRoomId === null ? abort(403) : $rooms->findOrFail($this->blocksRoomId);
        $this->authorize('update', $room);

        $addRoomBlock->handle($room, $this->validatedBlock());

        $this->reset('day', 'starts_at', 'ends_at', 'term_id', 'reason');
        $this->success('Bloqueo agregado', $this->describe($room));
    }

    public function removeBlock(string $id, RoomQuery $rooms, RemoveRoomBlock $removeRoomBlock): void
    {
        $block = $rooms->findBlock($id);

        if ($block === null) {
            return;
        }

        $this->authorize('update', $block->room);

        $removeRoomBlock->handle($block);

        $this->success('Bloqueo quitado', $this->describe($block->room));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'building_id' => 'edificio',
            'code' => 'clave',
            'name' => 'nombre',
            'type' => 'tipo',
            'capacity' => 'capacidad',
            'department_id' => 'departamento dueño',
            'features' => 'características',
            'day' => 'día',
            'starts_at' => 'hora de inicio',
            'ends_at' => 'hora de fin',
            'term_id' => 'periodo',
            'reason' => 'motivo',
        ];
    }

    /**
     * Los edificios de los campus que usan salones, agrupados por campus, para el filtro «Edificio» de la barra.
     *
     * @param  Collection<int, Campus>  $options
     * @return array<string, array{name: string, buildings: list<array{id: string, name: string}>}>
     */
    private function buildingGroups(BuildingQuery $buildingQuery, Collection $options): array
    {
        $campuses = $options->filter(fn (Campus $campus): bool => $campus->profile->usesRooms)->values();

        return $campuses->count() < 2 ? [] : collect($buildingQuery->options($campuses))->groupBy('campus')
            ->map(fn (Collection $group, string $code): array => ['name' => $campuses->firstWhere('code', $code)->name, 'buildings' => $group->map(fn (array $building): array => ['id' => $building['id'], 'name' => $building['name']])->all()])
            ->all();
    }

    /**
     * Los campus donde el usuario puede dar de alta un salón: los que usan salones y donde RoomPolicy::create lo
     * permite.
     *
     * @return Collection<int, Campus>
     */
    private function creatableCampuses(): Collection
    {
        return $this->campusOptions()
            ->filter(fn (Campus $campus): bool => $campus->profile->usesRooms && auth()->user()->can('create', [Room::class, $campus]))
            ->values();
    }

    /**
     * Los edificios del formulario: al editar, los del campus del salón, leídos en edit() (cambiar de campus no se hace aquí); en un
     * alta, los de los campus donde puede dar de alta, para agruparlos por campus.
     *
     * @return list<array{id: string, name: string, campus: string}>
     */
    private function formBuildings(BuildingQuery $buildings): array
    {
        if (! $this->showModal) {
            return [];
        }

        if ($this->editingId === null) {
            return $buildings->options($this->creatableCampuses());
        }

        return $this->editingBuildings;
    }

    /**
     * El salón del panel abierto. Si ya no existe o el usuario perdió su alcance, cierra el panel en lugar de
     * romper la página.
     */
    private function openBlocksRoom(RoomQuery $rooms): ?Room
    {
        if (! $this->showDrawer || $this->blocksRoomId === null) {
            return null;
        }

        try {
            $room = $rooms->findOrFail($this->blocksRoomId);
        } catch (ModelNotFoundException) {
            $room = null;
        }

        if ($room === null || ! auth()->user()->can('view', $room)) {
            $this->showDrawer = false;
            $this->blocksRoomId = null;

            return null;
        }

        return $room;
    }

    /**
     * Los datos validados en forma; la capacidad llega como entero y las características como lista.
     * Que el edificio sea del campus, que la capacidad sea mayor que cero y que el dueño exista lo hace
     * cumplir RoomRules.
     *
     * @return array{building_id: string, code: string, name: ?string, type: string, capacity: int, department_id: ?string, features: list<string>}
     */
    private function validatedRoom(): array
    {
        $data = $this->validate([
            'building_id' => ['required', 'string'],
            'code' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(RoomType::class)],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'department_id' => ['nullable', 'string'],
            'features' => ['nullable', 'string', 'max:1000', app(RoomRules::class)->validateFeatureLength(...)],
        ]);

        return [
            ...$data,
            'name' => $data['name'] ?? null,
            'capacity' => (int) $data['capacity'],
            'department_id' => ($data['department_id'] ?? '') === '' ? null : $data['department_id'],
            'features' => app(RoomRules::class)->featureList((string) $data['features']),
        ];
    }

    /**
     * Los datos del bloqueo validados en forma; que el día y las horas quepan en el perfil del campus lo
     * hace cumplir RoomRules.
     *
     * @return array{term_id: ?string, day: int, starts_at: string, ends_at: string, reason: string}
     */
    private function validatedBlock(): array
    {
        $data = $this->validate([
            'day' => ['required', 'integer'],
            'starts_at' => ['required', 'string'],
            'ends_at' => ['required', 'string'],
            'term_id' => ['nullable', 'string'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return [
            ...$data,
            'day' => (int) $data['day'],
            'term_id' => ($data['term_id'] ?? '') === '' ? null : $data['term_id'],
        ];
    }

    /**
     * Olvida el edificio, el tipo o el dueño del filtro que no está entre las opciones: los filtros se
     * recuerdan en la sesión, y uno que ya no existe (o es de otro campus) dejaría la lista vacía sin
     * verse en su select.
     *
     * @param  list<array{id: string, name: string}>  $buildings
     * @param  list<array{id: string, name: string}>  $types
     * @param  list<array{id: string, name: string}>  $owners
     */
    private function forgetFiltersOutside(array $buildings, array $types, array $owners): void
    {
        foreach ([['buildingFilter', $buildings], ['typeFilter', $types], ['ownerFilter', $owners]] as [$property, $options]) {
            if ($this->{$property} !== '' && ! in_array($this->{$property}, array_column($options, 'id'), true)) {
                $this->{$property} = '';
            }
        }
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Room $room): string
    {
        return e(trim("{$room->code} · {$room->name}", ' ·'));
    }
};
