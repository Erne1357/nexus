<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A quién le llega un aviso del proceso: holders() da, por permiso y objetivo, los usuarios activos que con alguno de
 * sus puestos vigentes lo tienen (cada uno con el puesto al que va el aviso). La regla es la misma de las policies
 * (Actor::rolesAllowing): un nombramiento sin campus vale en cualquier campus activo, y uno sobre una unidad cubre las
 * de su subárbol. Cuenta solo el nombramiento: el admin lo puede todo sin tenerlo, y los avisos van a quien hace el
 * trabajo; un admin que además tiene el nombramiento sí los recibe, con ese puesto.
 *
 * En SQL solo se acota a los usuarios activos con un nombramiento vigente de un puesto que concede el permiso; el
 * alcance, que es jerárquico en la unidad, lo decide rolesAllowing().
 */
final class RecipientsQuery
{
    public function __construct(
        private readonly Actor $context,
        private readonly PermissionRegistry $permissions,
    ) {}

    /**
     * A quién le llega un aviso por permiso: los usuarios activos que, con alguno de sus puestos vigentes (no solo el
     * activo), tienen `$permission` y cubren `$target` (Actor::rolesAllowing). El admin no entra por serlo.
     * Cada persona va una vez, con el primer puesto del enum que cubre. Ordenados por nombre.
     *
     * @return list<array{user: User, role: Role}>
     */
    public function holders(Permission $permission, Target $target): array
    {
        $roles = $this->permissions->rolesGranting($permission);

        if ($roles === []) {
            return [];
        }

        $candidates = User::query()
            ->where('is_active', true)
            ->whereHas('roleAssignments', fn (Builder $assignments) => $assignments->current()->whereIn('role', $roles))
            ->orderBy('name')
            ->get();

        $holders = [];

        foreach ($candidates as $user) {
            $role = $this->context->forUser($user)->rolesAllowing($permission, $target)[0] ?? null;

            if ($role !== null) {
                $holders[] = ['user' => $user, 'role' => $role];
            }
        }

        return $holders;
    }
}
