<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use Carbon\CarbonInterface;

/**
 * Qué usuarios tienen un nombramiento administrativo vigente en una fecha (tarea 1.54, SEL-07; supuesto por confirmar): uno
 * en un puesto de `Role::ADMINISTRATIVE`, de cualquier unidad, campus, carrera o modalidad. Es la única pregunta que otros
 * módulos hacen sobre los puestos administrativos, para no decidir con `Role::` fuera de Core/Authorization. Una consulta.
 */
final class AdministrativeAppointments
{
    /**
     * Los nombres de los puestos administrativos vigentes de cada usuario, por id de usuario. Los que no tienen no aparecen.
     *
     * @param  list<string>  $userIds
     * @return array<string, list<string>>
     */
    public function titlesOf(array $userIds, CarbonInterface $on): array
    {
        if ($userIds === []) {
            return [];
        }

        $day = $on->toImmutable()->setTimezone(config('app.timezone'))->toDateString();
        $titles = [];

        RoleAssignment::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('role', array_map(fn (Role $role): string => $role->value, Role::ADMINISTRATIVE))
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $day))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day))
            ->get(['user_id', 'role'])
            ->each(function (RoleAssignment $assignment) use (&$titles): void {
                $titles[$assignment->user_id][] = $assignment->role->label();
            });

        return $titles;
    }
}
