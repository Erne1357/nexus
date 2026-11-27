<?php

use App\Modules\Academic\Actions\SetSubjectRequiredSpace;
use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithModalityCampusFilter;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Scheduling\Actions\SetGroupCapacity;
use App\Modules\Scheduling\Queries\CampusGroupCountsQuery;
use App\Modules\Staffing\Queries\CurrentLoad;
use App\Modules\Staffing\Queries\CurrentLoadQuery;
use App\Modules\Staffing\Queries\CurrentLoadSubject;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Carga actual (tarea 1.57, SEL-18; maqueta 1-carga-b «agrupada por materia»): la lista de los grupos del departamento
 * en el periodo activo de la credencial, agrupada por materia, con sus sesiones y salones, docente, cupo y choques. La
 * ven quienes ven la carga del departamento (AcademicLoadPermission::LoadView). Edita ahí mismo, con las Actions y las
 * policies de siempre: el tipo de espacio de la materia (SetSubjectRequiredSpace, SubjectPolicy::setSpace, SEL-17) y el
 * cupo de cada grupo (SetGroupCapacity, GroupPolicy::manage); quien no puede las ve de solo lectura y llamarlas es 403.
 * Horario y docente se cambian por ahora en Horarios y Asignación docente (enlaces por grupo, `?grupo=`). Lee con
 * CurrentLoadQuery. La modalidad, el campus y el departamento se resuelven como en Asignación docente.
 */
new #[Title('Carga actual')] class extends Component
{
    use Toast;
    use WithModalityCampusFilter;

    /** Código del departamento; '' es el primero que puede ver. */
    #[Url(as: 'depto')]
    public string $department = '';

    #[Url(as: 'q')]
    public string $search = '';

    /** 'todos', 'sin_docente', 'sin_salon', 'choque' o 'sin_espacio'. */
    #[Url(as: 'estado')]
    public string $state = 'todos';

    /** Clave de la carrera dueña; '' todas. */
    #[Url(as: 'carrera')]
    public string $careerFilter = '';

    /**
     * El cupo escrito en cada grupo (id → texto), para los campos de la lista.
     *
     * @var array<string, string>
     */
    public array $capacities = [];

    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $modalityId = null;

    #[Locked]
    public ?string $campusId = null;

    #[Locked]
    public ?string $departmentId = null;

    /**
     * @var list<array{code: string, name: string}>
     */
    #[Locked]
    public array $departments = [];

    #[Locked]
    public ?string $message = null;

    public function mount(Actor $context): void
    {
        abort_unless($context->allowsAny(AcademicLoadPermission::LoadView), 403);

        $this->updatedState();
        $this->resolve($context, app(CurrentLoadQuery::class));
    }

    public function updatedCampus(Actor $context, CurrentLoadQuery $query): void
    {
        $chosen = $this->campusFilter();

        if ($chosen !== null) {
            session()->put(Campus::LAST_CHOSEN_KEY, $chosen->code);
        }

        $this->capacities = [];
        $this->resolve($context, $query);
    }

    public function updatedModalidad(Actor $context, CurrentLoadQuery $query): void
    {
        if (! $query->departmentsFor($context, $this->requiredModality()->getKey())->contains('code', $this->department)) {
            $this->department = '';
        }

        $this->updatedDepartment($context, $query);
    }

    public function updatedDepartment(Actor $context, CurrentLoadQuery $query): void
    {
        $this->reset('careerFilter', 'capacities');
        $this->resolve($context, $query);
    }

    public function updatedState(): void
    {
        $this->state = in_array($this->state, CurrentLoad::STATES, true) ? $this->state : 'todos';
    }

    /**
     * Cambia el tipo de espacio de una materia de la carga (SEL-17): aplica a todos sus grupos, hoy y en los periodos
     * siguientes. Solo el jefe de su departamento y el admin.
     */
    public function setSpace(string $subjectId, string $space, CurrentLoadQuery $query, SetSubjectRequiredSpace $action): void
    {
        abort_if($this->departmentId === null, 403);
        $subject = $query->subjectOrFail((string) $this->termId, (string) $this->modalityId, $this->departmentId, $subjectId);
        $this->authorize('setSpace', $subject);

        $choice = RequiredSpace::tryFrom($space);
        abort_if($choice === null, 422);

        $subject = $action->handle($subject, $choice, Auth::user());

        $this->success('Tipo de espacio guardado', e("{$subject->code} · {$choice->label()}. Aplica a todos sus grupos."));
    }

    /**
     * Guarda el cupo de un grupo (SetGroupCapacity: HC-05 y HC-07; la carga debe ser editable). Lo que rechaza se ve
     * junto al campo.
     */
    public function saveCapacity(string $groupId, CurrentLoadQuery $query, SetGroupCapacity $action): void
    {
        abort_if($this->departmentId === null, 403);
        $group = $query->groupOrFail((string) $this->termId, (string) $this->modalityId, $this->departmentId, $groupId, $this->campusId);
        $this->authorize('manage', $group);

        $this->resetErrorBag("capacities.{$group->id}");
        $value = trim((string) ($this->capacities[$group->id] ?? ''));

        if (! ctype_digit($value)) {
            $this->addError("capacities.{$group->id}", 'Escribe un número entero de lugares.');

            return;
        }

        try {
            $group = $action->handle($group, (int) $value, Auth::user());
        } catch (ValidationException $e) {
            $this->addError("capacities.{$group->id}", (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->capacities[$group->id] = (string) $group->capacity;
        $this->success('Cupo guardado', e("Grupo {$group->code}: {$group->capacity} lugares."));
    }

    public function with(CurrentLoadQuery $query, CampusGroupCountsQuery $counts): array
    {
        $modality = $this->modalityId === null ? null : $this->modalityOptions()->first(fn (Modality $option): bool => $option->getKey() === $this->modalityId);
        $scope = [
            'modalityOptions' => $this->modalityOptions(),
            'shownModality' => $modality,
            'campusOptions' => $modality === null ? collect() : $this->campusesOf($modality),
            'shownCampus' => $this->campusId === null || $modality === null ? null : $this->campusesOf($modality)->firstWhere('id', $this->campusId),
        ];

        if ($this->departmentId === null) {
            return ['board' => null, ...$scope];
        }

        $board = $query->board((string) $this->termId, (string) $this->modalityId, $this->departmentId, $this->campusId);
        $visible = $board->filtered($this->state, $this->careerFilter, $this->search);
        $this->syncCapacities($visible);
        $gate = Gate::forUser(Auth::user());
        $spaceAllowed = [];
        $manageAllowed = [];

        foreach ($visible as $subject) {
            $spaceAllowed[$subject->subject->id] = $gate->allows('setSpace', $subject->subject);

            foreach ($subject->groups as $row) {
                $manageAllowed[$row->group->id] = $gate->allows('manage', $row->group);
            }
        }

        return [
            ...$scope,
            'board' => $board,
            'visible' => $visible,
            'visibleCount' => array_sum(array_map(fn ($subject): int => count($subject->groups), $visible)),
            'spaceAllowed' => $spaceAllowed,
            'manageAllowed' => $manageAllowed,
            'campusCounts' => $counts->of((string) $this->termId, (string) $this->modalityId, $this->departmentId),
            'careers' => $board->careers(),
            'chips' => [
                'todos' => 'Todos',
                'sin_docente' => 'Sin docente',
                'sin_salon' => 'Sin salón',
                'choque' => 'Con choque',
                'sin_espacio' => 'Espacio sin marcar',
            ],
        ];
    }

    /**
     * Pone en los campos el cupo guardado de los grupos a la vista que el usuario no ha tocado.
     *
     * @param  list<CurrentLoadSubject>  $visible
     */
    private function syncCapacities(array $visible): void
    {
        foreach ($visible as $subject) {
            foreach ($subject->groups as $row) {
                $this->capacities[$row->group->id] ??= (string) $row->group->capacity;
            }
        }
    }

    private function resolve(Actor $context, CurrentLoadQuery $query): void
    {
        $this->termId = $this->modalityId = $this->campusId = $this->departmentId = null;
        $this->departments = [];

        $term = $context->loadTerm();
        $modality = $this->requiredModality();
        $this->modalidad = $modality->code;
        $campus = $this->campusFilter();
        $this->campus = $campus?->code;

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        $departments = $query->departmentsFor($context, $modality->getKey());
        $department = $this->department === ''
            ? $departments->first()
            : $departments->first(fn ($department): bool => $department->code === $this->department);

        abort_if($this->department !== '' && $department === null, 403);

        $this->departments = $departments->map(fn ($department): array => ['code' => $department->code, 'name' => "{$department->code} · {$department->name}"])->all();

        if ($department === null) {
            $this->message = "No tienes un departamento con carga en {$modality->name}.";

            return;
        }

        $this->department = $department->code;
        $this->termId = $term->getKey();
        $this->modalityId = $modality->getKey();
        $this->campusId = $campus?->getKey();
        $this->departmentId = $department->getKey();
        $this->message = null;
    }
};
