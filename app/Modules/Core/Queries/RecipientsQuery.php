<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * A quién le llega un aviso del proceso (tarea 2.9): los usuarios activos que, con `$role`, cubren el campus
 * y, si se piden, el departamento o la carrera. La regla es la misma de las policies (ScopedContext::covers):
 * un nombramiento sin campus vale en cualquier campus activo, y uno sobre una unidad cubre los departamentos de su
 * subárbol. Cuenta solo el nombramiento: el admin lo cubre todo sin tenerlo, y los avisos van a quien hace el
 * trabajo; un admin que además tiene el nombramiento sí los recibe.
 *
 * En SQL solo se acota por puesto vigente y carrera; la unidad, que es jerárquica, la decide coversWithScope().
 */
final class RecipientsQuery
{
    public function __construct(
        private readonly ScopedContext $context,
        private readonly PermissionRegistry $permissions,
    ) {}

    /**
     * A quién le llega un aviso por permiso: los usuarios activos que, con alguno de sus puestos vigentes (no solo el
     * activo), tienen `$permission` y cubren `$target` (ScopedContext::rolesAllowing). El admin no entra por serlo.
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

    /**
     * @deprecated Se borra en 3.P9 junto con coversWithScope(); usa holders().
     *
     * @return Collection<int, User>
     */
    public function for(Role $role, Campus|string $campus, ?string $department = null, ?string $career = null): Collection
    {
        $candidates = User::query()
            ->where('is_active', true)
            ->whereHas('roleAssignments', fn (Builder $assignments) => $assignments
                ->current()
                ->where('role', $role)
                ->when($career !== null, fn (Builder $query) => $query->where(fn (Builder $any) => $any->whereNull('career_id')->orWhere('career_id', $career))))
            ->orderBy('name')
            ->get();

        return $candidates
            ->filter(fn (User $user): bool => $this->context->forUser($user)->coversWithScope($role, $campus, $department, $career))
            ->values();
    }
}
