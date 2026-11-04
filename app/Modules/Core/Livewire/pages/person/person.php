<?php

use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Actions\EndRoleAssignment;
use App\Modules\Core\Enums\AssignmentEnding;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Queries\AssignmentLabels;
use App\Modules\Core\Queries\PeopleQuery;
use App\Modules\Core\Queries\PersonRecordQuery;
use App\Modules\Core\Rules\RoleAssignmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Ficha de una persona en «Personas y puestos» (3.P7, maqueta C): el encabezado con «Nombrar», las cifras, la línea de
 * vigencias, una tarjeta por nombramiento con «Terminar…», el panel «¿Quién ocupa un puesto?» y la bitácora de sus
 * puestos. Nombrar y terminar los hacen AppointRole y EndRoleAssignment, que hacen cumplir las reglas del alcance
 * (RoleAssignmentRules): sus errores salen junto al campo.
 *
 * Aquí solo se nombran y terminan Administración, Subdirección Académica y Coordinador (AZ-S11): el docente y las
 * jefaturas se ven de solo lectura, con el aviso de dónde se manejan, aunque la llamada llegue forzada desde el
 * navegador. El admin sí puede terminar su propio puesto de Administración si otro cubre los días que siguen; si no, queda deshabilitado con su motivo (AZ-S12), y quien pierde así su puesto vuelve a Inicio. Solo la ve el admin con ese perfil
 * activo (RoleAssignmentPolicy); el componente autoriza en mount(), al renderizar y en cada método público.
 */
new #[Title('Ficha de la persona')] class extends Component
{
    use Toast;

    /** La persona de la ficha. El navegador no la puede cambiar. */
    #[Locked]
    public string $personId = '';

    public bool $showModal = false;

    /** El puesto, el campus, la unidad y la carrera del modal «Nombrar»; el servidor solo usa los que el puesto pide. */
    public string $role = '';

    public string $campus = '';

    public string $unit = '';

    public string $career = '';

    public string $from = '';

    public string $to = '';

    /** El nombramiento que se está terminando. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $endingId = null;

    public string $lastDay = '';

    /** El panel «¿Quién ocupa un puesto?». */
    public string $whoRole = 'coordinador';

    public string $whoCampus = '';

    public string $whoUnit = '';

    public string $whoCareer = '';

    /** Búsqueda de otra persona, por nombre o usuario. */
    public string $find = '';

    public function mount(string $user, PeopleQuery $people): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        $this->personId = $people->findOrFail($user)->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(PeopleQuery $people, PersonRecordQuery $records, RoleAssignmentRules $rules, AssignmentLabels $labels): array
    {
        $this->authorize('viewAny', RoleAssignment::class);

        $person = $people->findOrFail($this->personId);
        $role = Role::tryFrom($this->role);
        $whoRole = Role::tryFrom($this->whoRole);

        return [
            'record' => $records->for($person),
            'canAppoint' => $person->is_active,
            'offered' => array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->label()], $this->offered($rules)),
            'roleCase' => $role,
            'options' => $role === null ? ['campuses' => [], 'units' => [], 'careers' => []] : $people->scopeOptions($role, $this->uuid($this->campus)),
            'whoRoles' => array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->label()], array_filter(Role::cases(), fn (Role $role): bool => $role !== Role::Docente)),
            'whoRoleCase' => $whoRole,
            'whoOptions' => $whoRole === null ? ['campuses' => [], 'units' => [], 'careers' => []] : $people->scopeOptions($whoRole, $this->uuid($this->whoCampus)),
            'who' => $whoRole === null ? null : $this->who($people, $labels, $whoRole),
            'found' => $people->search($this->find)->reject(fn ($found): bool => $found->id === $person->id)->values(),
        ];
    }

    /**
     * Cada cambio vuelve a autorizar, y un puesto o un campus nuevo olvida lo que dependía del anterior.
     */
    public function updated(string $property): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        if ($property === 'role') {
            $this->resetValidation();
            $this->reset('campus', 'unit', 'career');
        } elseif ($property === 'campus') {
            $this->reset('career');
        } elseif ($property === 'whoRole') {
            $this->reset('whoCampus', 'whoUnit', 'whoCareer');
        } elseif ($property === 'whoCampus') {
            $this->reset('whoCareer');
        }
    }

    public function openAppoint(): void
    {
        $this->authorize('create', RoleAssignment::class);

        $this->resetValidation();
        $this->reset('campus', 'unit', 'career', 'to');
        $this->role = Role::Coordinador->value;
        $this->from = today()->toDateString();
        $this->showModal = true;
    }

    public function appoint(AppointRole $appointRole, PeopleQuery $people, AssignmentLabels $labels, RoleAssignmentRules $rules): void
    {
        $this->authorize('create', RoleAssignment::class);

        $person = $people->findOrFail($this->personId);

        $this->validate([
            'role' => ['required', Rule::in(array_map(fn (Role $role): string => $role->value, $this->offered($rules)))],
            'from' => ['required', 'date'],
            'to' => ['nullable', 'date'],
        ], [
            'role.required' => 'Elige un puesto de la lista.',
            'role.in' => 'Elige un puesto de la lista.',
            'from.required' => 'Escribe la fecha de inicio.',
            'from.date' => 'La fecha de inicio no es válida.',
            'to.date' => 'La fecha de fin no es válida.',
        ]);

        $role = Role::from($this->role);
        $columns = $role->scopeColumns();

        $assignment = $appointRole->handle(
            $person,
            $role,
            in_array('campus_id', $columns, true) ? $people->campus($this->campus) : null,
            in_array('org_unit_id', $columns, true) ? $people->unit($this->unit) : null,
            in_array('career_id', $columns, true) && $this->career !== '' ? $this->career : null,
            CarbonImmutable::parse($this->from),
            $this->to === '' ? null : CarbonImmutable::parse($this->to),
        );

        $this->success('Nombramiento creado', e($labels->one($assignment)->summary));
        $this->showModal = false;
    }

    /**
     * Abre el recuadro «Terminar…» en la tarjeta del nombramiento, con hoy como último día.
     */
    public function confirmEnd(string $id, PeopleQuery $people, PersonRecordQuery $records): void
    {
        $assignment = $records->assignmentOf($people->findOrFail($this->personId), $id);
        $this->authorize('end', $assignment);

        $this->resetValidation();
        $this->endingId = $assignment->id;
        $this->lastDay = today()->toDateString();
    }

    public function cancelEnd(): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        $this->resetValidation();
        $this->endingId = null;
    }

    public function end(EndRoleAssignment $endRoleAssignment, PeopleQuery $people, PersonRecordQuery $records): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        if ($this->endingId === null) {
            return;
        }

        $assignment = $records->assignmentOf($people->findOrFail($this->personId), $this->endingId);
        $this->authorize('end', $assignment);

        $this->validate(['lastDay' => ['required', 'date']], [
            'lastDay.required' => 'Escribe la fecha de fin.',
            'lastDay.date' => 'La fecha de fin no es válida.',
        ]);

        if (($blocker = $records->endBlocker($assignment)) !== null) {
            throw ValidationException::withMessages(['lastDay' => $blocker]);
        }

        $day = CarbonImmutable::parse($this->lastDay);

        try {
            $ending = $endRoleAssignment->handle($assignment, $day);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['assignment'])) {
                throw ValidationException::withMessages(['lastDay' => $exception->errors()['assignment'][0]]);
            }

            throw $exception;
        }

        // Quien terminó su propio puesto de Administración desde hoy ya no ve esta pantalla: vuelve a Inicio en lugar
        // de un 403 al volver a pintarla.
        if ($assignment->user_id === auth()->id() && Gate::denies('viewAny', RoleAssignment::class)) {
            $this->success('Terminaste tu puesto de Administración.', e('Último día: '.$day->format('d/m/Y')), redirectTo: route('home'));

            return;
        }

        match ($ending) {
            AssignmentEnding::Ended => $this->success('Nombramiento terminado', e('Último día: '.$day->format('d/m/Y'))),
            AssignmentEnding::Removed => $this->success('Nombramiento quitado', 'Todavía no empezaba.'),
            AssignmentEnding::Unchanged => $this->info('Sin cambios', e('Ese nombramiento ya termina el '.$assignment->valid_to->format('d/m/Y').'.')),
        };

        $this->endingId = null;
    }

    /**
     * Los puestos que esta pantalla nombra: los que no se manejan en otra parte (AZ-S11).
     *
     * @return list<Role>
     */
    private function offered(RoleAssignmentRules $rules): array
    {
        return array_values(array_filter(Role::cases(), fn (Role $role): bool => $rules->managedElsewhere($role) === null));
    }

    /**
     * Quién ocupa el puesto sobre el alcance elegido en el panel: `incomplete` si falta elegir una columna del
     * puesto, `none` si nadie lo ocupa hoy, `found` con los titulares.
     *
     * @return array{state: string, summary: string, holders: Collection<int, RoleAssignment>}
     */
    private function who(PeopleQuery $people, AssignmentLabels $labels, Role $role): array
    {
        $values = [
            'campus_id' => $this->uuid($this->whoCampus),
            'org_unit_id' => $this->uuid($this->whoUnit),
            'career_id' => $this->uuid($this->whoCareer),
        ];
        $columns = $role->scopeColumns();
        $summary = $labels->one(new RoleAssignment([
            'role' => $role,
            ...array_intersect_key($values, array_flip($columns)),
        ]))->summary;

        if (array_filter($columns, fn (string $column): bool => $values[$column] === null) !== []) {
            return ['state' => 'incomplete', 'summary' => $summary, 'holders' => collect()];
        }

        $holders = $people->holders($role, $values['campus_id'], $values['org_unit_id'], $values['career_id']);

        return ['state' => $holders->isEmpty() ? 'none' : 'found', 'summary' => $summary, 'holders' => $holders];
    }

    /**
     * El valor solo si es un id: lo que llega del navegador no se pasa a la base si no lo es.
     */
    private function uuid(string $value): ?string
    {
        return Str::isUuid($value) ? $value : null;
    }
};
