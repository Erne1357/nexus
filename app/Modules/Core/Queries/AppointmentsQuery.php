<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * La lista plana de la pestaña «Nombramientos» de «Personas y puestos» (U3): un renglón por nombramiento con su
 * persona, por nombre. Es de los puestos que el admin nombra: no trae los de docente (AZ-S11, se manejan en la ficha del
 * docente) ni los de alumno (decenas de miles, AC-15). Filtra por persona (nombre o usuario), puesto, modalidad, campus
 * (`$campus` un id, o `all` para los que valen en todos los campus) y vigencia (solo los vigentes hoy, o todos).
 */
final class AppointmentsQuery
{
    public const ALL_CAMPUSES = 'all';

    /**
     * @return LengthAwarePaginator<int, RoleAssignment>
     */
    public function forTable(string $search = '', ?Role $role = null, ?string $modalityId = null, ?string $campus = null, bool $onlyCurrent = true, int $perPage = 15): LengthAwarePaginator
    {
        return RoleAssignment::query()
            ->select('role_assignments.*')
            ->join('users', 'users.id', '=', 'role_assignments.user_id')
            ->whereNotIn('role_assignments.role', [Role::Docente, Role::Alumno])
            ->when(trim($search) !== '', fn (Builder $query) => $query->whereHas('user', fn (Builder $user) => TextSearch::apply($user, ['name', 'username'], $search)))
            ->when($role !== null, fn (Builder $query) => $query->where('role_assignments.role', $role))
            ->when($modalityId !== null && Str::isUuid($modalityId), fn (Builder $query) => $query->where('role_assignments.modality_id', $modalityId))
            ->when($campus === self::ALL_CAMPUSES, fn (Builder $query) => $query->whereNull('role_assignments.campus_id'))
            ->when($campus !== null && Str::isUuid($campus), fn (Builder $query) => $query->where('role_assignments.campus_id', $campus))
            ->when($onlyCurrent, fn (Builder $query) => $query->current())
            ->with('user')
            ->orderBy('users.name')
            ->orderBy('role_assignments.role')
            ->orderBy('role_assignments.valid_from')
            ->orderBy('role_assignments.id')
            ->paginate($perPage, pageName: 'appointmentsPage');
    }
}
