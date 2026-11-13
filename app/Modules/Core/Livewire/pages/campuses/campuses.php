<?php

use App\Modules\Core\Actions\CreateCampus;
use App\Modules\Core\Actions\UpdateCampus;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\CampusQuery;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * CRUD de Campus, el módulo de referencia: tabla con búsqueda y filtro, alta y edición en un modal y
 * activar o desactivar desde la tabla. No hay borrar: es un catálogo.
 *
 * El campus es solo el lugar (MS3, supuesto por confirmar): código, nombre y estado, sin modalidad ni perfil de flujo,
 * que se administran en Modalidades (U4 opción A). Un campus nuevo nace presencial (CreateCampus).
 *
 * El componente lee con CampusQuery, autoriza con CampusPolicy en mount() y en cada acción (una
 * petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y llama a
 * CreateCampus o UpdateCampus, que hacen cumplir las reglas del campus. Solo el admin da de alta y edita; los demás
 * ven la tabla, con los controles deshabilitados y su motivo, igual que el filtro de inactivos.
 */
new #[Title('Campus')] class extends Component
{
    use Toast, WithPagination;

    /** Búsqueda por código o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** '' (todos), 'active' o 'inactive'; se recuerda en la sesión del usuario. */
    #[Session]
    public string $status = '';

    public bool $showModal = false;

    /** El campus que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Campus::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(CampusQuery $campuses, Actor $context): array
    {
        $page = $campuses->forTable($context, $this->search, match ($this->status) {
            'active' => true,
            'inactive' => false,
            default => null,
        });

        return [
            'campuses' => $page,
            'canCreate' => Gate::allows('create', Campus::class),
            'canSeeInactive' => $context->allows(CorePermission::CampusViewInactive),
            'headers' => [
                ['key' => 'code', 'label' => 'Código'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-36 min-[1200px]:min-w-52'],
                ['key' => 'is_active', 'label' => 'Estado'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de un inactivo pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (Campus $campus): bool => ! $campus->is_active],
            'statuses' => [['id' => 'active', 'name' => 'Activos'], ['id' => 'inactive', 'name' => 'Inactivos']],
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
        $this->authorize('create', Campus::class);

        $this->resetValidation();
        $this->reset('editingId', 'code', 'name');
        $this->showModal = true;
    }

    public function edit(string $id, CampusQuery $campuses): void
    {
        $campus = $campuses->findOrFail($id);
        $this->authorize('update', $campus);

        $this->resetValidation();
        $this->editingId = $campus->id;
        $this->code = $campus->code;
        $this->name = $campus->name;
        $this->showModal = true;
    }

    public function save(CreateCampus $createCampus, UpdateCampus $updateCampus, CampusQuery $campuses): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', Campus::class);
            $campus = $createCampus->handle($this->validatedCampus());
            $this->success('Campus creado', $this->describe($campus));
        } else {
            $campus = $campuses->findOrFail($this->editingId);
            $this->authorize('update', $campus);

            $campus = $updateCampus->handle($campus, $this->validatedCampus());
            $this->success('Campus actualizado', $this->describe($campus));
        }

        $this->showModal = false;
    }

    public function toggleActive(string $id, CampusQuery $campuses, UpdateCampus $updateCampus): void
    {
        $campus = $campuses->findOrFail($id);
        $this->authorize('update', $campus);

        $campus = $updateCampus->handle($campus, ['is_active' => ! $campus->is_active]);

        $this->success($campus->is_active ? 'Campus activado' : 'Campus desactivado', $this->describe($campus));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'code' => 'código',
            'name' => 'nombre',
        ];
    }

    /**
     * Código y nombre, validados en forma.
     *
     * @return array{code: string, name: string}
     */
    private function validatedCampus(): array
    {
        $validated = $this->validate([
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        return ['code' => $validated['code'], 'name' => $validated['name']];
    }

    /**
     * Código y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Campus $campus): string
    {
        return e("{$campus->code} · {$campus->name}");
    }
};
