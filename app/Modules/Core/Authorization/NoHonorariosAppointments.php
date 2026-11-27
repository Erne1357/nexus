<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use Carbon\CarbonInterface;

/**
 * PL-04 (tarea 1.81, confirmado el 27 de noviembre de 2026: «Solo jefes de depto y arriba» no pueden tener honorarios): de
 * una lista de usuarios, los que tienen un nombramiento vigente en una fecha en un puesto de `Role::NO_HONORARIOS` (jefatura
 * de departamento, subdirección o dirección), de cualquier unidad. Como AdministrativeAppointments, es la única pregunta
 * que otros módulos hacen sobre estos puestos, para no decidir con `Role::` fuera de Core/Authorization. Una consulta.
 */
final class NoHonorariosAppointments
{
    /**
     * @param  list<string>  $userIds
     * @return list<string> Los ids de los usuarios que tienen alguno de esos puestos.
     */
    public function among(array $userIds, CarbonInterface $on): array
    {
        if ($userIds === []) {
            return [];
        }

        $day = $on->toImmutable()->setTimezone(config('app.timezone'))->toDateString();

        return RoleAssignment::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('role', array_map(fn (Role $role): string => $role->value, Role::NO_HONORARIOS))
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $day))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day))
            ->distinct()
            ->pluck('user_id')
            ->all();
    }
}
