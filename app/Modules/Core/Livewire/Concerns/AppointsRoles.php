<?php

namespace App\Modules\Core\Livewire\Concerns;

use App\Modules\Core\Actions\AppointCareerCoordinator;
use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\PeopleQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Los campos del formulario «Nombrar» (puesto, modalidad, campus, unidad, carrera y fechas), iguales en «Personas y
 * puestos» y en el panel del Organigrama: limpia lo que dependía del puesto o del campus anterior, valida y llama a
 * AppointRole. La vista es `core::people.appoint-fields`. La unidad puede venir fija (el Organigrama) y entonces no se
 * elige. La modalidad (AZ-S17) viene elegida si hay una sola activa; el campus del coordinador y del jefe de proyecto
 * docente es opcional. El coordinador se nombra sobre varias carreras a la vez (U3 opción A: `careers`, las casillas
 * del cajón; M5, MS19) con AppointCareerCoordinator; sin casillas elegidas usa `career` (el Organigrama).
 */
trait AppointsRoles
{
    /**
     * El puesto, la modalidad, el campus, la unidad, la carrera y las fechas; el servidor solo usa las columnas que el
     * puesto pide o admite.
     */
    public string $role = '';

    public string $modality = '';

    public string $campus = '';

    public string $unit = '';

    public string $career = '';

    /** @var list<string> las carreras marcadas en el cajón del coordinador */
    public array $careers = [];

    /** Búsqueda dentro de la lista de carreras del cajón. */
    public string $careerSearch = '';

    public string $from = '';

    public string $to = '';

    /**
     * Deja el formulario listo para un nombramiento nuevo: el puesto, desde hoy y, si viene fija, la unidad.
     */
    protected function resetAppointFields(Role $role, ?OrgUnit $lockedUnit = null): void
    {
        $this->resetValidation();
        $this->reset('campus', 'career', 'careers', 'careerSearch', 'to');
        $this->role = $role->value;
        $this->modality = app(PeopleQuery::class)->defaultModalityId() ?? '';
        $this->unit = $lockedUnit?->getKey() ?? '';
        $this->from = today()->toDateString();
    }

    /**
     * Un puesto nuevo olvida el alcance del anterior (y la unidad si no es fija) y vuelve a la modalidad propuesta; un
     * campus nuevo, la carrera.
     */
    protected function appointFieldUpdated(string $property, bool $unitLocked = false): void
    {
        if ($property === 'role') {
            $this->resetValidation();
            $this->reset('campus', 'career', 'careers', 'careerSearch');
            $this->modality = app(PeopleQuery::class)->defaultModalityId() ?? '';

            if (! $unitLocked) {
                $this->reset('unit');
            }
        } elseif ($property === 'campus') {
            $this->keepOfferedCareers();
        }
    }

    /**
     * Un campus nuevo conserva las carreras marcadas que también se ofrecen en él y suelta las demás, para no perder
     * lo marcado mientras la petición del campus está en camino.
     */
    private function keepOfferedCareers(): void
    {
        $campus = Str::isUuid($this->campus) ? $this->campus : null;
        $offered = [];

        foreach (app(PeopleQuery::class)->careerChecklist($campus, $this->modality) as $group) {
            array_push($offered, ...array_column($group['careers'], 'id'));
        }

        $this->careers = array_values(array_intersect($this->careers, $offered));

        if (! in_array($this->career, $offered, true)) {
            $this->reset('career');
        }
    }

    /**
     * La persona a la que se nombra, para no marcar las carreras que ya coordina; sin ella (el Organigrama), ninguna.
     */
    protected function appointee(): ?User
    {
        return null;
    }

    /**
     * «Todas las de la modalidad en el campus»: marca las carreras que se ofrecen en el campus elegido y que la persona
     * no coordina ya. Sin un campus que exista, no marca nada.
     */
    public function selectAllInCampus(PeopleQuery $people): void
    {
        $this->authorize('create', RoleAssignment::class);

        $this->careers = $people->campus($this->campus) === null ? [] : $this->selectableCareerIds($people, $this->campus);
    }

    /**
     * «Todas las de la modalidad»: sin campus, marca todas las que se ofrecen en algún campus activo.
     */
    public function selectAllInModality(PeopleQuery $people): void
    {
        $this->authorize('create', RoleAssignment::class);

        $this->campus = '';
        $this->careers = $this->selectableCareerIds($people, null);
    }

    /**
     * Marca las carreras de un departamento o, si ya están todas, las desmarca.
     */
    public function toggleDepartment(PeopleQuery $people, string $departmentId): void
    {
        $this->authorize('create', RoleAssignment::class);

        $group = collect($people->careerChecklist($this->campus, $this->modality, $this->appointee()))->firstWhere('id', $departmentId);
        $ids = $group === null ? [] : array_column(array_filter($group['careers'], fn (array $career): bool => $career['state'] !== 'mine'), 'id');

        $this->careers = array_values($ids !== [] && array_diff($ids, $this->careers) === []
            ? array_diff($this->careers, $ids)
            : array_unique([...$this->careers, ...$ids]));
    }

    /**
     * @return list<string>
     */
    private function selectableCareerIds(PeopleQuery $people, ?string $campusId): array
    {
        $ids = [];

        foreach ($people->careerChecklist($campusId, $this->modality, $this->appointee()) as $group) {
            foreach ($group['careers'] as $career) {
                if ($career['state'] !== 'mine') {
                    $ids[] = $career['id'];
                }
            }
        }

        return $ids;
    }

    /**
     * @param  list<array{id: string, name: string}>  $offered  los puestos que el formulario ofrece
     * @return list<RoleAssignment> uno por carrera para el coordinador; uno solo para los demás puestos
     */
    protected function appointPerson(AppointRole $appointRole, PeopleQuery $people, User $person, array $offered, ?OrgUnit $lockedUnit = null): array
    {
        $this->validate([
            'role' => ['required', Rule::in(array_column($offered, 'id'))],
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
        $columns = [...$role->scopeColumns(), ...$role->optionalScopeColumns()];
        $campus = in_array('campus_id', $columns, true) ? $people->campus($this->campus) : null;

        // Solo el campo vacío es «Todos los campus» (AZ-S17): un id que no es de un campus no se vuelve un nombramiento
        // de todos los campus.
        if (in_array('campus_id', $columns, true) && $this->campus !== '' && $campus === null) {
            throw ValidationException::withMessages(['campus' => 'Elige el campus.']);
        }

        if ($role === Role::Coordinador) {
            return $this->appointCoordinator($people, $person, $campus);
        }

        return [$appointRole->handle(
            $person,
            $role,
            $campus,
            in_array('org_unit_id', $columns, true) ? ($lockedUnit ?? $people->unit($this->unit)) : null,
            in_array('career_id', $columns, true) && $this->career !== '' ? $this->career : null,
            CarbonImmutable::parse($this->from),
            $this->to === '' ? null : CarbonImmutable::parse($this->to),
            in_array('modality_id', $columns, true) ? $people->modality($this->modality) : null,
        )];
    }

    /**
     * El coordinador sobre las carreras marcadas (o la única de `career`), en una sola transacción.
     *
     * @return list<RoleAssignment>
     */
    private function appointCoordinator(PeopleQuery $people, User $person, ?Campus $campus): array
    {
        $modality = $people->modality($this->modality);

        if ($modality === null) {
            $none = $this->careers === [] && $this->career === '';

            throw ValidationException::withMessages(['modality' => 'Elige la modalidad.', ...($none ? ['career' => 'Elige al menos una carrera.'] : [])]);
        }

        $careers = $this->careers !== [] ? $this->careers : ($this->career === '' ? [] : [$this->career]);

        return app(AppointCareerCoordinator::class)->handle(
            $person,
            $modality,
            $campus,
            array_values(array_filter($careers, fn (string $id): bool => Str::isUuid($id))),
            CarbonImmutable::parse($this->from),
            $this->to === '' ? null : CarbonImmutable::parse($this->to),
        );
    }
}
