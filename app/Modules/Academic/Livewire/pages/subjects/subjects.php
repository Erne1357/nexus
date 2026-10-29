<?php

use App\Modules\Academic\Actions\CreateSubject;
use App\Modules\Academic\Actions\UpdateSubject;
use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Queries\SubjectQuery;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * CRUD de Materias, con la forma de Departamentos: tabla con búsqueda y filtros por departamento,
 * tipo y estado; alta y edición en un modal; activar o desactivar desde la tabla. Las materias no se
 * borran. Es del admin.
 *
 * El componente lee con SubjectQuery, autoriza con SubjectPolicy en mount() y en cada acción (una
 * petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y llama a
 * CreateSubject o UpdateSubject, que hacen cumplir las reglas. Desactivar una materia que todavía
 * necesitan alumnos lo veta Students: el motivo sale en un toast de error.
 *
 * Los campos del formulario se llaman como las columnas, así los errores de las Actions caen en su
 * campo.
 */
new #[Title('Materias')] class extends Component
{
    use Toast, WithPagination;

    /** Búsqueda por clave o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** Id del departamento; '' son todos. */
    #[Session]
    public string $departmentFilter = '';

    /** Valor de SubjectKind o SubjectQuery::WITHOUT_KIND; '' son todos. */
    #[Session]
    public string $kindFilter = '';

    /** '' (todas), 'active' o 'inactive'. */
    #[Session]
    public string $status = '';

    public bool $showModal = false;

    /** La materia que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $department_id = '';

    public string $theory_hours = '';

    public string $practice_hours = '';

    public string $credits = '';

    /** Valor de SubjectKind; '' es sin tipo. */
    public string $kind = '';

    public bool $needs_lab = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Subject::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(SubjectQuery $subjects): array
    {
        $departments = $subjects->departmentOptions();
        $kinds = array_map(fn (SubjectKind $kind): array => ['id' => $kind->value, 'name' => $kind->label()], SubjectKind::cases());
        $kindFilters = [...$kinds, ['id' => SubjectQuery::WITHOUT_KIND, 'name' => 'Sin tipo']];
        $this->forgetFiltersOutside($departments, $kindFilters);

        return [
            'subjects' => $subjects->forTable(
                $this->search,
                $this->departmentFilter === '' ? null : $this->departmentFilter,
                $this->kindFilter === '' ? null : $this->kindFilter,
                match ($this->status) {
                    'active' => true,
                    'inactive' => false,
                    default => null,
                },
            ),
            'departments' => $departments,
            'kinds' => $kinds,
            'kindFilters' => $kindFilters,
            'statuses' => [['id' => 'active', 'name' => 'Activas'], ['id' => 'inactive', 'name' => 'Inactivas']],
            // El tipo y el laboratorio son las columnas descriptivas: se ocultan debajo de 1200 px.
            'headers' => [
                ['key' => 'code', 'label' => 'Clave'],
                ['key' => 'name', 'label' => 'Nombre'],
                ['key' => 'department', 'label' => 'Departamento'],
                ['key' => 'hours', 'label' => 'HT-HP-Créditos'],
                ['key' => 'kind', 'label' => 'Tipo', 'class' => 'max-[1200px]:hidden'],
                ['key' => 'needs_lab', 'label' => 'Laboratorio', 'class' => 'max-[1200px]:hidden'],
                ['key' => 'study_plans_count', 'label' => 'Planes'],
                ['key' => 'is_active', 'label' => 'Estado'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de una inactiva pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (Subject $subject): bool => ! $subject->is_active],
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'departmentFilter', 'kindFilter', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('create', Subject::class);

        $this->resetValidation();
        $this->reset('editingId', 'code', 'name', 'department_id', 'theory_hours', 'practice_hours', 'credits', 'kind', 'needs_lab');
        $this->showModal = true;
    }

    public function edit(string $id, SubjectQuery $subjects): void
    {
        $subject = $subjects->findOrFail($id);
        $this->authorize('update', $subject);

        $this->resetValidation();
        $this->editingId = $subject->id;
        $this->code = $subject->code;
        $this->name = $subject->name;
        $this->department_id = $subject->department_id;
        $this->theory_hours = (string) $subject->theory_hours;
        $this->practice_hours = (string) $subject->practice_hours;
        $this->credits = (string) $subject->credits;
        $this->kind = $subject->kind?->value ?? '';
        $this->needs_lab = $subject->needs_lab;
        $this->showModal = true;
    }

    public function save(CreateSubject $createSubject, UpdateSubject $updateSubject, SubjectQuery $subjects): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', Subject::class);
            $subject = $createSubject->handle($this->validatedSubject());
            $this->success('Materia creada', $this->describe($subject));
        } else {
            $subject = $subjects->findOrFail($this->editingId);
            $this->authorize('update', $subject);
            $subject = $updateSubject->handle($subject, $this->validatedSubject());
            $this->success('Materia actualizada', $this->describe($subject));
        }

        $this->showModal = false;
    }

    public function toggleActive(string $id, SubjectQuery $subjects, UpdateSubject $updateSubject): void
    {
        $subject = $subjects->findOrFail($id);
        $this->authorize('update', $subject);

        try {
            $subject = $updateSubject->handle($subject, ['is_active' => ! $subject->is_active]);
        } catch (ValidationException $exception) {
            $this->error('No se pudo desactivar '.e($subject->code), e(implode(' ', $exception->errors()['is_active'] ?? [])));

            return;
        }

        $this->success($subject->is_active ? 'Materia activada' : 'Materia desactivada', $this->describe($subject));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'code' => 'clave',
            'name' => 'nombre',
            'department_id' => 'departamento',
            'theory_hours' => 'horas de teoría',
            'practice_hours' => 'horas de práctica',
            'credits' => 'créditos',
            'kind' => 'tipo',
        ];
    }

    /**
     * Los datos validados en forma; el tope de horas y créditos es el de la columna (smallint).
     *
     * @return array{code: string, name: string, department_id: string, theory_hours: int, practice_hours: int, credits: int, kind: string, needs_lab: bool}
     */
    private function validatedSubject(): array
    {
        $data = $this->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['required', 'string'],
            'theory_hours' => ['required', 'integer', 'min:0', 'max:32767'],
            'practice_hours' => ['required', 'integer', 'min:0', 'max:32767'],
            'credits' => ['required', 'integer', 'min:0', 'max:32767'],
            'kind' => ['nullable', Rule::enum(SubjectKind::class)],
            'needs_lab' => ['boolean'],
        ]);

        return [
            ...$data,
            'theory_hours' => (int) $data['theory_hours'],
            'practice_hours' => (int) $data['practice_hours'],
            'credits' => (int) $data['credits'],
            'kind' => (string) $data['kind'],
        ];
    }

    /**
     * Olvida el departamento o el tipo del filtro que no está entre las opciones: los filtros se
     * recuerdan en la sesión, y uno que ya no existe dejaría la lista vacía sin verse en su select.
     *
     * @param  list<array{id: string, name: string}>  $departments
     * @param  list<array{id: string, name: string}>  $kinds
     */
    private function forgetFiltersOutside(array $departments, array $kinds): void
    {
        if ($this->departmentFilter !== '' && ! in_array($this->departmentFilter, array_column($departments, 'id'), true)) {
            $this->departmentFilter = '';
        }

        if ($this->kindFilter !== '' && ! in_array($this->kindFilter, array_column($kinds, 'id'), true)) {
            $this->kindFilter = '';
        }
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Subject $subject): string
    {
        return e("{$subject->code} · {$subject->name}");
    }
};
