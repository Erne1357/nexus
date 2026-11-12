<?php

namespace App\Modules\Core\Livewire\Concerns;

use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\PeopleQuery;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * Los campos del formulario «Nombrar» (puesto, modalidad, campus, unidad, carrera y fechas), iguales en «Personas y
 * puestos» y en el panel del Organigrama: limpia lo que dependía del puesto o del campus anterior, valida y llama a
 * AppointRole. La vista es `core::people.appoint-fields`. La unidad puede venir fija (el Organigrama) y entonces no se
 * elige. La modalidad (AZ-S17) viene elegida si hay una sola activa; el campus del coordinador y del jefe de proyecto
 * docente es opcional. El diseño final de este formulario es de la tarea T13 (U3).
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

    public string $from = '';

    public string $to = '';

    /**
     * Deja el formulario listo para un nombramiento nuevo: el puesto, desde hoy y, si viene fija, la unidad.
     */
    protected function resetAppointFields(Role $role, ?OrgUnit $lockedUnit = null): void
    {
        $this->resetValidation();
        $this->reset('campus', 'career', 'to');
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
            $this->reset('campus', 'career');
            $this->modality = app(PeopleQuery::class)->defaultModalityId() ?? '';

            if (! $unitLocked) {
                $this->reset('unit');
            }
        } elseif ($property === 'campus') {
            $this->reset('career');
        }
    }

    /**
     * @param  list<array{id: string, name: string}>  $offered  los puestos que el formulario ofrece
     */
    protected function appointPerson(AppointRole $appointRole, PeopleQuery $people, User $person, array $offered, ?OrgUnit $lockedUnit = null): RoleAssignment
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

        return $appointRole->handle(
            $person,
            $role,
            in_array('campus_id', $columns, true) ? $people->campus($this->campus) : null,
            in_array('org_unit_id', $columns, true) ? ($lockedUnit ?? $people->unit($this->unit)) : null,
            in_array('career_id', $columns, true) && $this->career !== '' ? $this->career : null,
            CarbonImmutable::parse($this->from),
            $this->to === '' ? null : CarbonImmutable::parse($this->to),
            in_array('modality_id', $columns, true) ? $people->modality($this->modality) : null,
        );
    }
}
