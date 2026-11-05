<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;

/**
 * El nombre y la descripción de un puesto para quien lo ocupa (D20: la credencial nombra su unidad): Role::titleOn() y
 * Role::descriptionOn() con las unidades de sus nombramientos de ese puesto. Lo usan la credencial de la banda e Inicio;
 * «Actuando como» (RoleSummaryQuery) y las etiquetas de los nombramientos (AssignmentLabels) llaman a Role con las
 * unidades que ya leyeron.
 */
final class RoleTitleQuery
{
    /**
     * Una consulta, solo si alguno de sus nombramientos de `$role` lleva unidad.
     *
     * @return array{title: string, description: string}
     */
    public function of(Actor $context, Role $role): array
    {
        $units = $this->unitsOf($context->heldScopes($role)->pluck('org_unit_id')->filter()->unique()->values()->all());

        return ['title' => $role->titleOn($units), 'description' => $role->descriptionOn($units)];
    }

    /**
     * Las unidades, borradas incluidas (un nombramiento sigue nombrando la suya), como las piden Role::titleOn() y
     * Role::descriptionOn(), en el orden de sus nombres.
     *
     * @param  list<string>  $ids
     * @return list<array{kind: OrgUnitKind, code: string, name: string}>
     */
    private function unitsOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return OrgUnit::withTrashed()->whereKey($ids)->orderBy('name')->get(['id', 'kind', 'code', 'name'])
            ->map(fn (OrgUnit $unit): array => ['kind' => $unit->kind, 'code' => $unit->code, 'name' => $unit->name])
            ->values()
            ->all();
    }
}
