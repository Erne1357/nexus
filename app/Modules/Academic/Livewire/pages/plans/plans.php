<?php

use App\Modules\Academic\Actions\CreateStudyPlan;
use App\Modules\Academic\Actions\UpdateStudyPlan;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Queries\CareerQuery;
use App\Modules\Academic\Queries\StudyPlanQuery;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * CRUD de Planes de estudio, con la forma de Materias: tabla con búsqueda y filtros por carrera y
 * estado; alta y edición en un modal; activar o desactivar desde la tabla. Los planes no se borran.
 * Es del admin.
 *
 * El componente lee con StudyPlanQuery, autoriza con StudyPlanPolicy en mount() y en cada acción
 * (una petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y
 * llama a CreateStudyPlan o UpdateStudyPlan, que hacen cumplir las reglas. Cambiar la carrera de un
 * plan con alumnos lo veta Students: el motivo sale en el campo de la carrera.
 *
 * Los campos del formulario se llaman como las columnas, así los errores de las Actions caen en su
 * campo.
 */
new #[Title('Planes de estudio')] class extends Component
{
    use Toast, WithPagination;

    /** Búsqueda por clave o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** Id de la carrera; '' son todas. */
    #[Session]
    public string $careerFilter = '';

    /** '' (todos), 'active' o 'inactive'. */
    #[Session]
    public string $status = '';

    public bool $showModal = false;

    /** El plan que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $career_id = '';

    public string $code = '';

    public string $name = '';

    public string $version = '';

    public string $semesters_count = '';

    public string $total_credits = '';

    /** Fecha (Y-m-d) desde la que rige; '' es sin capturar. */
    public string $valid_from = '';

    public function mount(): void
    {
        $this->authorize('viewAny', StudyPlan::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(StudyPlanQuery $plans, CareerQuery $careerQuery): array
    {
        $careers = $careerQuery->options();

        if ($this->careerFilter !== '' && ! in_array($this->careerFilter, array_column($careers, 'id'), true)) {
            $this->careerFilter = '';
        }

        return [
            'plans' => $plans->forTable(
                $this->search,
                $this->careerFilter === '' ? null : $this->careerFilter,
                match ($this->status) {
                    'active' => true,
                    'inactive' => false,
                    default => null,
                },
            ),
            'careers' => $careers,
            'statuses' => [['id' => 'active', 'name' => 'Activos'], ['id' => 'inactive', 'name' => 'Inactivos']],
            // La versión y los semestres son las columnas descriptivas: se ocultan debajo de 1200 px.
            'headers' => [
                ['key' => 'code', 'label' => 'Clave'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-36 min-[1200px]:min-w-52'],
                ['key' => 'career', 'label' => 'Carrera', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'version', 'label' => 'Versión', 'class' => 'max-[1535px]:hidden'],
                ['key' => 'semesters_count', 'label' => 'Semestres', 'class' => 'max-[1535px]:hidden'],
                ['key' => 'total_credits', 'label' => 'Créditos', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'placements_count', 'label' => 'Materias', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'is_active', 'label' => 'Estado'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de un inactivo pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (StudyPlan $plan): bool => ! $plan->is_active],
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'careerFilter', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('create', StudyPlan::class);

        $this->resetValidation();
        $this->reset('editingId', 'career_id', 'code', 'name', 'version', 'semesters_count', 'total_credits', 'valid_from');
        $this->showModal = true;
    }

    public function edit(string $id, StudyPlanQuery $plans): void
    {
        $plan = $plans->findOrFail($id);
        $this->authorize('update', $plan);

        $this->resetValidation();
        $this->editingId = $plan->id;
        $this->career_id = $plan->career_id;
        $this->code = $plan->code;
        $this->name = $plan->name;
        $this->version = (string) $plan->version;
        $this->semesters_count = (string) $plan->semesters_count;
        $this->total_credits = (string) $plan->total_credits;
        $this->valid_from = $plan->valid_from?->format('Y-m-d') ?? '';
        $this->showModal = true;
    }

    public function save(CreateStudyPlan $createPlan, UpdateStudyPlan $updatePlan, StudyPlanQuery $plans): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', StudyPlan::class);
            $plan = $createPlan->handle($this->validatedPlan());
            $this->success('Plan creado', $this->describe($plan));
        } else {
            $plan = $plans->findOrFail($this->editingId);
            $this->authorize('update', $plan);
            $plan = $updatePlan->handle($plan, $this->validatedPlan());
            $this->success('Plan actualizado', $this->describe($plan));
        }

        $this->showModal = false;
    }

    public function toggleActive(string $id, StudyPlanQuery $plans, UpdateStudyPlan $updatePlan): void
    {
        $plan = $plans->findOrFail($id);
        $this->authorize('update', $plan);

        try {
            $plan = $updatePlan->handle($plan, ['is_active' => ! $plan->is_active]);
        } catch (ValidationException $exception) {
            $this->error('No se pudo cambiar '.e($plan->code), e(implode(' ', array_merge(...array_values($exception->errors())))));

            return;
        }

        $this->success($plan->is_active ? 'Plan activado' : 'Plan desactivado', $this->describe($plan));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'career_id' => 'carrera',
            'code' => 'clave',
            'name' => 'nombre',
            'version' => 'versión',
            'semesters_count' => 'semestres',
            'total_credits' => 'créditos',
            'valid_from' => 'fecha de inicio',
        ];
    }

    /**
     * Los datos validados en forma; el tope de semestres y créditos es el de la columna (smallint).
     * Vacío es «sin capturar» (null).
     *
     * @return array{career_id: string, code: string, name: string, version: string, semesters_count: ?int, total_credits: ?int, valid_from: ?string}
     */
    private function validatedPlan(): array
    {
        $data = $this->validate([
            'career_id' => ['required', 'string'],
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:255'],
            'semesters_count' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'total_credits' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            ...$data,
            'semesters_count' => ($data['semesters_count'] ?? '') === '' ? null : (int) $data['semesters_count'],
            'total_credits' => ($data['total_credits'] ?? '') === '' ? null : (int) $data['total_credits'],
            'valid_from' => ($data['valid_from'] ?? '') === '' ? null : $data['valid_from'],
        ];
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(StudyPlan $plan): string
    {
        return e("{$plan->code} · {$plan->name}");
    }
};
