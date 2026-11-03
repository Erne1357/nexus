<?php

namespace App\Modules\Core\Queries;

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
    public function __construct(private readonly ScopedContext $context) {}

    /**
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
