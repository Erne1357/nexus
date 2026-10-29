<?php

use App\Modules\Academic\Actions\CreateCareer;
use App\Modules\Academic\Actions\DeleteCareer;
use App\Modules\Academic\Actions\SetCareerOffering;
use App\Modules\Academic\Actions\UpdateCareer;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Queries\CareerQuery;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * CRUD de Carreras: tabla con búsqueda y filtros por departamento y estado, alta y edición en un
 * modal, activar o desactivar, ofrecer o dejar de ofrecer en el campus activo y borrar (solo sin
 * planes). Es del admin; copia la forma de Departamentos.
 *
 * El componente lee con CareerQuery, autoriza con CareerPolicy en mount() y en cada acción (una
 * petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y llama
 * a CreateCareer, UpdateCareer, SetCareerOffering o DeleteCareer, que hacen cumplir las reglas. La
 * oferta es del campus activo de la credencial (ScopedContext::activeCampus()), nunca de un campo del
 * formulario.
 */
new #[Title('Carreras')] class extends Component
{
    use Toast, WithPagination;

    /** Búsqueda por clave o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** '' (todos), 'active' o 'inactive'; se recuerda en la sesión del usuario. */
    #[Session]
    public string $status = '';

    /** Id del departamento del filtro, '' para todos; se recuerda en la sesión del usuario. */
    #[Session]
    public string $department = '';

    public bool $showModal = false;

    /** La carrera que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $department_id = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Career::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(CareerQuery $careers, ScopedContext $context): array
    {
        $campus = $context->activeCampus();
        $departments = $careers->departmentOptions();
        $filter = $departments->contains('id', $this->department) ? $this->department : null;

        return [
            'careers' => $careers->forTable($campus, $this->search, $filter, match ($this->status) {
                'active' => true,
                'inactive' => false,
                default => null,
            }),
            'campus' => $campus,
            'headers' => [
                ['key' => 'code', 'label' => 'Clave'],
                ['key' => 'name', 'label' => 'Nombre'],
                ['key' => 'department', 'label' => 'Departamento', 'sortable' => false],
                ['key' => 'study_plans_count', 'label' => 'Planes'],
                ['key' => 'offered_here', 'label' => $campus === null ? 'Se ofrece en el campus' : "Se ofrece en {$campus->code}"],
                ['key' => 'is_active', 'label' => 'Estado'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de un inactivo pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (Career $career): bool => ! $career->is_active],
            'statuses' => [['id' => 'active', 'name' => 'Activas'], ['id' => 'inactive', 'name' => 'Inactivas']],
            'departmentOptions' => $departments->map(fn ($department): array => ['id' => $department->id, 'name' => "{$department->code} · {$department->name}"])->all(),
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'department'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('create', Career::class);

        $this->resetValidation();
        $this->reset('editingId', 'code', 'name', 'department_id');
        $this->showModal = true;
    }

    public function edit(string $id, CareerQuery $careers): void
    {
        $career = $careers->findOrFail($id);
        $this->authorize('update', $career);

        $this->resetValidation();
        $this->editingId = $career->id;
        $this->code = $career->code;
        $this->name = $career->name;
        $this->department_id = $career->department_id;
        $this->showModal = true;
    }

    public function save(CreateCareer $createCareer, UpdateCareer $updateCareer, CareerQuery $careers): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', Career::class);
            $career = $createCareer->handle($this->validatedCareer());
            $this->success('Carrera creada', $this->describe($career));
        } else {
            $career = $careers->findOrFail($this->editingId);
            $this->authorize('update', $career);
            $career = $updateCareer->handle($career, $this->validatedCareer());
            $this->success('Carrera actualizada', $this->describe($career));
        }

        $this->showModal = false;
    }

    public function toggleActive(string $id, CareerQuery $careers, UpdateCareer $updateCareer): void
    {
        $career = $careers->findOrFail($id);
        $this->authorize('update', $career);

        $career = $updateCareer->handle($career, ['is_active' => ! $career->is_active]);

        $this->success($career->is_active ? 'Carrera activada' : 'Carrera desactivada', $this->describe($career));
    }

    /**
     * Ofrece la carrera en el campus activo o deja de ofrecerla, según lo que haga hoy.
     */
    public function toggleOffering(string $id, CareerQuery $careers, ScopedContext $context, SetCareerOffering $setCareerOffering): void
    {
        $career = $careers->findOrFail($id);
        $this->authorize('update', $career);

        $campus = $context->activeCampus();

        if ($campus === null) {
            $this->error('Elige un campus', 'Elige un campus en tu credencial para ofrecer carreras.');

            return;
        }

        $offeredNow = $career->offerings()->where('campus_id', $campus->id)->where('is_active', true)->exists();
        $offering = $setCareerOffering->handle($career, $campus, ! $offeredNow);
        $description = e("{$career->code} · {$campus->code}");

        if ($offeredNow) {
            $this->success('Carrera fuera de la oferta', $description);
        } elseif (! $offering->is_active) {
            $this->warning('Oferta registrada, inactiva', e("{$career->code} está inactiva en {$campus->code}: la oferta cuenta hasta que se active y se vuelva a ofrecer."));
        } else {
            $this->success('Carrera ofrecida', $description);
        }
    }

    public function delete(string $id, CareerQuery $careers, DeleteCareer $deleteCareer): void
    {
        $career = $careers->findOrFail($id);
        $this->authorize('delete', $career);

        try {
            $deleteCareer->handle($career);
        } catch (ValidationException $exception) {
            $this->error('No se pudo borrar '.e($career->code), e(implode(' ', $exception->errors()['career'] ?? [])));

            return;
        }

        $this->success('Carrera borrada', $this->describe($career));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['code' => 'clave', 'name' => 'nombre', 'department_id' => 'departamento'];
    }

    /**
     * Clave, nombre y departamento validados en forma.
     *
     * @return array{code: string, name: string, department_id: string}
     */
    private function validatedCareer(): array
    {
        $data = $this->validate([
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['required', 'string'],
        ]);

        /** @var array{code: string, name: string, department_id: string} */
        return $data;
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Career $career): string
    {
        return e("{$career->code} · {$career->name}");
    }
};
