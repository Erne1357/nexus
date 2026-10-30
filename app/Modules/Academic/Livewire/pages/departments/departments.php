<?php

use App\Modules\Academic\Actions\CreateDepartment;
use App\Modules\Academic\Actions\DeleteDepartment;
use App\Modules\Academic\Actions\UpdateDepartment;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Queries\DepartmentQuery;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * CRUD de Departamentos, la primera pantalla de administración de Academic: tabla con búsqueda y
 * filtro, alta y edición en un modal, activar o desactivar y borrar desde la tabla (solo sin carreras
 * ni materias). Es del admin; las demás pantallas de catálogo de Academic copian esta forma.
 *
 * El componente lee con DepartmentQuery, autoriza con DepartmentPolicy en mount() y en cada acción
 * (una petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y
 * llama a CreateDepartment, UpdateDepartment o DeleteDepartment, que hacen cumplir las reglas.
 */
new #[Title('Departamentos')] class extends Component
{
    use Toast, WithPagination;

    /** Búsqueda por clave o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** '' (todos), 'active' o 'inactive'; se recuerda en la sesión del usuario. */
    #[Session]
    public string $status = '';

    public bool $showModal = false;

    /** El departamento que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Department::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(DepartmentQuery $departments): array
    {
        return [
            'departments' => $departments->forTable($this->search, match ($this->status) {
                'active' => true,
                'inactive' => false,
                default => null,
            }),
            'headers' => [
                ['key' => 'code', 'label' => 'Clave'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-36 min-[1200px]:min-w-52'],
                ['key' => 'careers_count', 'label' => 'Carreras'],
                ['key' => 'subjects_count', 'label' => 'Materias', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'is_active', 'label' => 'Estado'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de un inactivo pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (Department $department): bool => ! $department->is_active],
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
        $this->authorize('create', Department::class);

        $this->resetValidation();
        $this->reset('editingId', 'code', 'name');
        $this->showModal = true;
    }

    public function edit(string $id, DepartmentQuery $departments): void
    {
        $department = $departments->findOrFail($id);
        $this->authorize('update', $department);

        $this->resetValidation();
        $this->editingId = $department->id;
        $this->code = $department->code;
        $this->name = $department->name;
        $this->showModal = true;
    }

    public function save(CreateDepartment $createDepartment, UpdateDepartment $updateDepartment, DepartmentQuery $departments): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', Department::class);
            $department = $createDepartment->handle($this->validatedDepartment());
            $this->success('Departamento creado', $this->describe($department));
        } else {
            $department = $departments->findOrFail($this->editingId);
            $this->authorize('update', $department);
            $department = $updateDepartment->handle($department, $this->validatedDepartment());
            $this->success('Departamento actualizado', $this->describe($department));
        }

        $this->showModal = false;
    }

    public function toggleActive(string $id, DepartmentQuery $departments, UpdateDepartment $updateDepartment): void
    {
        $department = $departments->findOrFail($id);
        $this->authorize('update', $department);

        $department = $updateDepartment->handle($department, ['is_active' => ! $department->is_active]);

        $this->success($department->is_active ? 'Departamento activado' : 'Departamento desactivado', $this->describe($department));
    }

    public function delete(string $id, DepartmentQuery $departments, DeleteDepartment $deleteDepartment): void
    {
        $department = $departments->findOrFail($id);
        $this->authorize('delete', $department);

        try {
            $deleteDepartment->handle($department);
        } catch (ValidationException $exception) {
            $this->error('No se pudo borrar '.e($department->code), e(implode(' ', $exception->errors()['department'] ?? [])));

            return;
        }

        $this->success('Departamento borrado', $this->describe($department));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['code' => 'clave', 'name' => 'nombre'];
    }

    /**
     * Clave y nombre validados en forma.
     *
     * @return array{code: string, name: string}
     */
    private function validatedDepartment(): array
    {
        /** @var array{code: string, name: string} */
        return $this->validate([
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Department $department): string
    {
        return e("{$department->code} · {$department->name}");
    }
};
