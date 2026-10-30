<?php

use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\StudentQuery;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Listado de alumnos (tarea 1.6): solo lectura, de un campus, el activo de la credencial. Lee con StudentQuery,
 * que deja ver lo mismo que StudentPolicy, y autoriza `viewAny` en mount(). Los filtros se recuerdan en la
 * sesión del usuario y vuelven a la primera página al cambiar (DESIGN.md, "Anatomía de una pantalla de
 * catálogo"). No hay acción principal: los alumnos llegan de la importación del SII.
 */
new #[Title('Alumnos')] class extends Component
{
    use WithPagination;

    /** Número de control o nombre. */
    #[Session]
    public string $search = '';

    /** Id de la carrera; '' son todas. */
    #[Session]
    public string $career = '';

    /** Id del plan; '' son todos. */
    #[Session]
    public string $plan = '';

    /** Semestre; '' son todos. */
    #[Session]
    public string $semester = '';

    /** Valor de StudentStatus o StudentQuery::WITHOUT_STATUS; '' son todos. */
    #[Session]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Student::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(StudentQuery $students, ScopedContext $context): array
    {
        $campus = $context->activeCampus();
        $options = $campus === null ? ['careers' => [], 'plans' => [], 'semesters' => []] : $students->filterOptions($context, $campus);
        $this->forgetFiltersOutside($options);
        $filters = array_filter(['career' => $this->career, 'plan' => $this->plan, 'semester' => $this->semester, 'status' => $this->status], fn (string $value): bool => $value !== '');

        return [
            'campus' => $campus,
            'students' => $campus === null ? null : $students->forTable($context, $campus, $filters, $this->search),
            'options' => $options,
            'filtered' => $filters !== [] || trim($this->search) !== '',
            'statuses' => [
                ...array_map(fn (StudentStatus $status): array => ['id' => $status->value, 'name' => $status->label()], StudentStatus::cases()),
                ['id' => StudentQuery::WITHOUT_STATUS, 'name' => 'Sin estatus'],
            ],
            // La carrera completa, el plan y los créditos son las columnas largas: se ocultan debajo de 1200 px.
            'headers' => [
                ['key' => 'control_number', 'label' => 'Control'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-[1200px]:min-w-48 min-[1800px]:whitespace-nowrap'],
                ['key' => 'career', 'label' => 'Carrera'],
                ['key' => 'plan', 'label' => 'Plan', 'class' => 'max-[1200px]:hidden'],
                ['key' => 'current_semester', 'label' => 'Semestre', 'class' => 'text-right'],
                ['key' => 'credits', 'label' => 'Créditos', 'class' => 'text-right max-[1200px]:hidden'],
                ['key' => 'status', 'label' => 'Estatus'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de un inactivo pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (Student $student): bool => ! $student->is_active],
        ];
    }

    /**
     * Olvida la carrera, el plan o el semestre que no están entre las opciones del campus de la credencial: los
     * filtros se recuerdan en la sesión, y uno de otro campus dejaría la lista vacía sin mostrarse en su select.
     *
     * @param  array{careers: list<array{id: string, name: string}>, plans: list<array{id: string, name: string}>, semesters: list<array{id: int, name: string}>}  $options
     */
    private function forgetFiltersOutside(array $options): void
    {
        foreach (['career' => 'careers', 'plan' => 'plans', 'semester' => 'semesters'] as $property => $list) {
            if ($this->{$property} !== '' && ! in_array($this->{$property}, array_map('strval', array_column($options[$list], 'id')), true)) {
                $this->{$property} = '';
            }
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'career', 'plan', 'semester', 'status'], true)) {
            $this->resetPage();
        }
    }
};
