<?php

use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Actions\CreateOrgUnit;
use App\Modules\Core\Actions\EndRoleAssignment;
use App\Modules\Core\Actions\MoveOrgUnit;
use App\Modules\Core\Actions\SetOrgUnitActive;
use App\Modules\Core\Actions\UpdateOrgUnit;
use App\Modules\Core\Enums\AssignmentEnding;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Livewire\Concerns\AppointsRoles;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Queries\AssignmentLabels;
use App\Modules\Core\Queries\OrgChartQuery;
use App\Modules\Core\Queries\OrgUnitDetail;
use App\Modules\Core\Queries\PeopleQuery;
use App\Modules\Core\Rules\OrgUnitRules;
use App\Modules\Core\Rules\RoleAssignmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * El panel de la unidad elegida en el Organigrama (3.P7, maqueta B): su lugar, su titular, los puestos vigentes o por
 * empezar sobre ella, sus personas y sus hijas (OrgChartQuery::detail()), y sus acciones: «Renombrar», «Mover»,
 * «Agregar hija» y «Desactivar» o «Activar». Escriben las Actions de Core (CreateOrgUnit, UpdateOrgUnit, MoveOrgUnit y
 * SetOrgUnitActive), que hacen cumplir OrgUnitRules (AZ-S13); sus errores salen junto al campo. Lo que no se puede
 * hacer queda deshabilitado con su motivo escrito, y abrir el formulario de lo bloqueado lo rechaza con ese motivo.
 *
 * Arranca en la raíz; la página le pide otra unidad con `org-unit-picked` y el alta con `org-unit-create`. Después de
 * cada escritura avisa al diagrama con `org-chart-changed` (y la unidad que queda elegida); mover a la misma madre o
 * guardar sin cambios solo dice «Sin cambios». Solo lo usa el admin con
 * ese perfil activo (OrgUnitPolicy): autoriza al pintar y en cada método, cada escritura con su ability.
 *
 * Los puestos que van sobre la unidad se nombran y terminan aquí (P1, confirmado el 3 de noviembre de 2026): «Nombrar»
 * es el formulario de «Personas y puestos» (AppointsRoles) con la unidad fija y la persona por buscar, y «Terminar…» es
 * el recuadro de la ficha de la persona. Los roles y sus permisos siguen en código.
 */
new class extends Component
{
    use AppointsRoles;
    use Toast;

    /** La unidad del panel. El navegador no la puede cambiar. */
    #[Locked]
    public ?string $unitId = null;

    public bool $showForm = false;

    /** 'create', 'edit' o 'move': qué hace el formulario. El navegador no lo puede cambiar. */
    #[Locked]
    public string $mode = '';

    public string $name = '';

    public string $code = '';

    public string $kind = '';

    /** La unidad de la que depende, al dar de alta o mover. */
    public string $parent = '';

    public bool $showAppoint = false;

    /** Búsqueda de la persona a nombrar, por nombre o usuario. */
    public string $findPerson = '';

    public ?string $appointUserId = null;

    /** El nombramiento que se está terminando. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $endingId = null;

    public string $lastDay = '';

    public function mount(OrgChartQuery $query): void
    {
        $this->authorize('viewAny', OrgUnit::class);

        $this->unitId = $query->rootId();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(OrgChartQuery $query, OrgUnitRules $rules, PeopleQuery $people): array
    {
        $this->authorize('viewAny', OrgUnit::class);

        try {
            $detail = $query->detail((string) $this->unitId);
        } catch (ModelNotFoundException) {
            $this->unitId = $query->rootId();
            $detail = $query->detail($this->unitId);
        }

        return [
            'detail' => $detail,
            'reasons' => $this->reasons($detail),
            'parentOptions' => match ($this->mode) {
                'create' => $query->parentOptions(),
                'move' => $query->parentOptions($detail->unit),
                default => [],
            },
            'kinds' => $this->kinds($detail->unit, $rules),
            'fixedKind' => $this->mode === 'edit' ? $rules->fixedKindReason($detail->unit) : null,
            'offered' => array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->label()], $detail->appointRoles),
            'roleCase' => Role::tryFrom($this->role),
            'options' => $this->showAppoint && Role::tryFrom($this->role) !== null
                ? $people->scopeOptions(Role::from($this->role), Str::isUuid($this->campus) ? $this->campus : null)
                : ['campuses' => [], 'units' => [], 'careers' => []],
            'person' => $this->showAppoint && $this->appointUserId !== null ? $people->findOrFail($this->appointUserId) : null,
            'found' => $this->showAppoint && $this->appointUserId === null ? $people->search($this->findPerson) : collect(),
        ];
    }

    /**
     * Cerrar el formulario con la X, el velo o Esc olvida lo que tenía.
     */
    public function updated(string $property): void
    {
        $this->authorize('viewAny', OrgUnit::class);

        if ($property === 'showForm' && ! $this->showForm) {
            $this->closeForm();
        } elseif ($property === 'showAppoint' && ! $this->showAppoint) {
            $this->closeAppoint();
        }

        $this->appointFieldUpdated($property, unitLocked: true);
    }

    #[On('org-unit-picked')]
    public function pick(string $id, OrgChartQuery $query): void
    {
        $this->authorize('viewAny', OrgUnit::class);

        $this->unitId = $query->findOrFail($id)->id;
        $this->closeForm();
        $this->closeAppoint();
        $this->reset('endingId');
    }

    /**
     * «Nueva unidad» y «Agregar hija»: sin madre, la unidad del panel si admite hijas y, si no, la Dirección. «Agregar
     * hija» no manda la unidad: un botón pintado antes de elegir otra caja mandaría la anterior, y el servidor ya sabe
     * cuál es la del panel.
     */
    #[On('org-unit-create')]
    public function openCreate(OrgChartQuery $query, OrgUnitRules $rules, ?string $parentId = null): void
    {
        $this->authorize('viewAny', OrgUnit::class);
        $this->authorize('create', OrgUnit::class);

        $parent = $parentId === null ? null : $query->unit($parentId);

        if ($parent === null) {
            $current = $query->unit($this->unitId);
            $parent = $current !== null && $rules->parentProblem($current) === null ? $current : $query->unit($query->rootId());
        }

        $this->openForm('create');
        $this->parent = $parent->id;
        $this->kind = OrgUnitKind::Departamento->value;
    }

    public function openEdit(OrgChartQuery $query, OrgUnitRules $rules): void
    {
        $this->authorize('viewAny', OrgUnit::class);
        $unit = $query->findOrFail((string) $this->unitId);
        $this->authorize('update', $unit);

        if (($locked = $rules->lockedReason($unit)) !== null) {
            $this->addError('unit', $locked);

            return;
        }

        $this->openForm('edit');
        $this->name = $unit->name;
        $this->code = $unit->code;
        $this->kind = $unit->kind->value;
    }

    public function openMove(OrgChartQuery $query, OrgUnitRules $rules): void
    {
        $this->authorize('viewAny', OrgUnit::class);
        $unit = $query->findOrFail((string) $this->unitId);
        $this->authorize('move', $unit);

        if (($blocker = $rules->moveBlocker($unit)) !== null) {
            $this->addError('unit', $blocker);

            return;
        }

        $this->openForm('move');
        $this->parent = (string) $unit->parent_id;
    }

    public function closeForm(): void
    {
        $this->authorize('viewAny', OrgUnit::class);

        $this->resetValidation();
        $this->reset('showForm', 'mode', 'name', 'code', 'kind', 'parent');
    }

    /**
     * Cierra «Nombrar» y olvida la persona, la búsqueda y los campos.
     */
    public function closeAppoint(): void
    {
        $this->authorize('viewAny', OrgUnit::class);

        $this->resetValidation();
        $this->reset('showAppoint', 'findPerson', 'appointUserId', 'role', 'campus', 'unit', 'career', 'from', 'to');
    }

    /**
     * «Nombrar»: abre el formulario sobre la unidad del panel, con el puesto del titular elegido; si la unidad no admite
     * puestos o está inactiva, lo rechaza con el motivo.
     */
    public function openAppoint(OrgChartQuery $query, RoleAssignmentRules $rules): void
    {
        $this->authorize('viewAny', OrgUnit::class);
        $this->authorize('create', RoleAssignment::class);

        $unit = $query->findOrFail((string) $this->unitId);

        if (($blocker = $query->appointBlocker($unit)) !== null) {
            $this->addError('unit', $blocker);

            return;
        }

        $this->closeForm();
        $this->reset('findPerson', 'appointUserId', 'endingId');
        $this->resetAppointFields($rules->titularRoleOf($unit) ?? $rules->rolesForUnit($unit)[0], $unit);
        $this->showAppoint = true;
    }

    public function choosePerson(string $id, PeopleQuery $people): void
    {
        $this->authorize('viewAny', OrgUnit::class);
        $this->authorize('create', RoleAssignment::class);

        $this->appointUserId = $people->findOrFail($id)->id;
        $this->findPerson = '';
        $this->resetValidation('appointUserId');
    }

    public function appoint(AppointRole $appointRole, OrgChartQuery $query, PeopleQuery $people, AssignmentLabels $labels, RoleAssignmentRules $rules): void
    {
        $this->authorize('viewAny', OrgUnit::class);
        $this->authorize('create', RoleAssignment::class);

        $unit = $query->findOrFail((string) $this->unitId);

        if ($this->appointUserId === null) {
            $this->addError('appointUserId', 'Elige a la persona.');

            return;
        }

        $person = $people->findOrFail($this->appointUserId);
        $offered = array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->label()], $rules->rolesForUnit($unit));

        $assignment = $this->appointPerson($appointRole, $people, $person, $offered, $unit);

        $this->success('Nombramiento creado', e($labels->one($assignment)->summary));
        $this->closeAppoint();
        $this->dispatch('org-chart-changed', id: $unit->id);
    }

    /**
     * Abre el recuadro «Terminar…» en el renglón, con el primer último día posible (AZ-S12).
     */
    public function confirmEnd(string $id, OrgChartQuery $query, RoleAssignmentRules $rules): void
    {
        $this->authorize('viewAny', OrgUnit::class);

        $assignment = $query->assignmentOf($query->findOrFail((string) $this->unitId), $id);
        $this->authorize('end', $assignment);

        $this->resetValidation();
        $this->endingId = $assignment->id;
        $this->lastDay = ($rules->earliestLastDay($assignment) ?? today()->toImmutable())->toDateString();
    }

    public function cancelEnd(): void
    {
        $this->authorize('viewAny', OrgUnit::class);

        $this->resetValidation();
        $this->endingId = null;
    }

    public function end(EndRoleAssignment $endRoleAssignment, OrgChartQuery $query, RoleAssignmentRules $rules): void
    {
        $this->authorize('viewAny', OrgUnit::class);

        if ($this->endingId === null) {
            return;
        }

        $unit = $query->findOrFail((string) $this->unitId);
        $assignment = $query->assignmentOf($unit, $this->endingId);
        $this->authorize('end', $assignment);

        $this->validate(['lastDay' => ['required', 'date']], [
            'lastDay.required' => 'Escribe la fecha de fin.',
            'lastDay.date' => 'La fecha de fin no es válida.',
        ]);

        if (($blocker = $rules->endBlocker($assignment)) !== null) {
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

        match ($ending) {
            AssignmentEnding::Ended => $this->success('Nombramiento terminado', e('Último día: '.$day->format('d/m/Y'))),
            AssignmentEnding::Removed => $this->success('Nombramiento quitado', 'Todavía no empezaba.'),
            AssignmentEnding::Unchanged => $this->info('Sin cambios', e('Ese nombramiento ya termina el '.$assignment->valid_to->format('d/m/Y').'.')),
        };

        $this->endingId = null;
        $this->dispatch('org-chart-changed', id: $unit->id);
    }

    /**
     * Guarda el alta o el cambio de nombre, clave o tipo.
     */
    public function save(CreateOrgUnit $createOrgUnit, UpdateOrgUnit $updateOrgUnit, OrgChartQuery $query): void
    {
        $this->authorize('viewAny', OrgUnit::class);

        if ($this->mode === 'create') {
            $this->authorize('create', OrgUnit::class);
            $data = $this->validatedUnit(withParent: true);

            $parent = $query->unit($this->parent);

            if ($parent === null) {
                $this->addError('parent', 'Elige de qué unidad depende.');

                return;
            }

            $unit = $createOrgUnit->handle($parent, $data);
            $this->unitId = $unit->id;
            $this->success('Unidad creada', e("{$unit->code} · {$unit->name}"));
        } elseif ($this->mode === 'edit') {
            $unit = $query->findOrFail((string) $this->unitId);
            $this->authorize('update', $unit);
            $data = $this->validatedUnit(withParent: false);

            if ($data['code'] === $unit->code && $data['name'] === $unit->name && $data['kind'] === $unit->kind) {
                $this->info('Sin cambios', e("{$unit->code} · {$unit->name}"));
                $this->closeForm();

                return;
            }

            $unit = $updateOrgUnit->handle($unit, $data);
            $this->success('Cambio guardado', e("{$unit->code} · {$unit->name}"));
        } else {
            return;
        }

        $this->closeForm();
        $this->dispatch('org-chart-changed', id: $unit->id);
    }

    public function move(MoveOrgUnit $moveOrgUnit, OrgChartQuery $query): void
    {
        $this->authorize('viewAny', OrgUnit::class);
        $unit = $query->findOrFail((string) $this->unitId);
        $this->authorize('move', $unit);

        $this->validate(['parent' => ['required', 'string']], ['parent.required' => 'Elige de qué unidad depende.']);

        $parent = $query->unit($this->parent);

        if ($parent === null) {
            $this->addError('parent', 'Elige de qué unidad depende.');

            return;
        }

        if ($parent->id === $unit->parent_id) {
            $this->info('Sin cambios', e("{$unit->name} ya depende de {$parent->name}"));
            $this->closeForm();

            return;
        }

        $unit = $moveOrgUnit->handle($unit, $parent);

        $this->success('Unidad movida', e("{$unit->name} ahora depende de {$parent->name}"));
        $this->closeForm();
        $this->dispatch('org-chart-changed', id: $unit->id);
    }

    public function setActive(bool $active, SetOrgUnitActive $setOrgUnitActive, OrgChartQuery $query): void
    {
        $this->authorize('viewAny', OrgUnit::class);
        $unit = $query->findOrFail((string) $this->unitId);
        $this->authorize('setActive', $unit);

        $this->resetValidation();
        $unit = $setOrgUnitActive->handle($unit, $active);

        $this->success($unit->is_active ? 'Unidad activada' : 'Unidad desactivada', e("{$unit->code} · {$unit->name}"));
        $this->dispatch('org-chart-changed', id: $unit->id);
    }

    /**
     * Por qué no se puede cada acción, para escribirlo debajo de los botones: «Mover: …», «Agregar hija: …» y
     * «Desactivar: …» (o «Activar: …» si está inactiva). Un departamento académico solo dice dónde se edita.
     *
     * @return list<string>
     */
    private function reasons(OrgUnitDetail $detail): array
    {
        if ($detail->lockedReason !== null) {
            return [$detail->lockedReason];
        }

        $reasons = [];

        if ($detail->moveBlocker !== null) {
            $reasons[] = "Mover: {$detail->moveBlocker}";
        }

        if ($detail->childBlocker !== null) {
            $reasons[] = "Agregar hija: {$detail->childBlocker}";
        }

        if ($detail->unit->is_active) {
            foreach ($detail->deactivationBlockers as $blocker) {
                $reasons[] = "Desactivar: {$blocker}";
            }
        } elseif ($detail->activationBlocker !== null) {
            $reasons[] = "Activar: {$detail->activationBlocker}";
        }

        return $reasons;
    }

    /**
     * @return array{code: string, name: string, kind: OrgUnitKind}
     */
    private function validatedUnit(bool $withParent): array
    {
        $this->name = trim($this->name);
        $this->code = trim($this->code);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(OrgUnitKind::class)],
            ...($withParent ? ['parent' => ['required', 'string']] : []),
        ], [
            'name.required' => 'Escribe el nombre.',
            'name.max' => 'El nombre es demasiado largo.',
            'code.required' => 'Escribe la clave.',
            'code.max' => 'La clave es demasiado larga.',
            'kind.required' => 'Elige el tipo.',
            'kind.enum' => 'Elige un tipo de la lista.',
            'parent.required' => 'Elige de qué unidad depende.',
        ]);

        return ['code' => $this->code, 'name' => $this->name, 'kind' => OrgUnitKind::from($this->kind)];
    }

    private function openForm(string $mode): void
    {
        $this->resetValidation();
        $this->reset('name', 'code', 'kind', 'parent', 'showAppoint', 'endingId');
        $this->mode = $mode;
        $this->showForm = true;
    }

    /**
     * Los tipos que se eligen: sin Dirección ni Departamento académico (AZ-S13). Al renombrar una unidad cuyo tipo no
     * cambia (la Dirección y Subdirección Académica), solo el suyo.
     *
     * @return list<array{id: string, name: string}>
     */
    private function kinds(OrgUnit $unit, OrgUnitRules $rules): array
    {
        $kinds = $this->mode === 'edit' && $rules->fixedKindReason($unit) !== null
            ? [$unit->kind]
            : array_filter(OrgUnitKind::cases(), fn (OrgUnitKind $kind): bool => ! in_array($kind, [OrgUnitKind::Direccion, OrgUnitKind::DepartamentoAcademico], true));

        return array_values(array_map(fn (OrgUnitKind $kind): array => ['id' => $kind->value, 'name' => $kind->label()], $kinds));
    }
};
