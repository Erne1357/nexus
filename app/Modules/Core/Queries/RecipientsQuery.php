<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\AssignmentScope;
use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\Permission;
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
 * En SQL solo se acota a los usuarios activos con un nombramiento vigente de un puesto que tiene el permiso hoy
 * (GrantTable, CF-01); el
 * alcance, que es jerárquico en la unidad, lo decide rolesAllowing().
 */
final class RecipientsQuery
{
    public function __construct(
        private readonly Actor $context,
        private readonly GrantTable $grants,
    ) {}

    /**
     * A quién le llega un aviso por permiso: los usuarios activos que, con alguno de sus puestos vigentes (no solo el
     * activo), tienen `$permission` y cubren `$target` (Actor::rolesAllowing). El admin no entra por serlo.
     * Cada persona va una vez, con el primer puesto del enum que cubre y el nombramiento por el que lo cubre (`scope`, el
     * más específico de ese puesto; CE-02, el buzón del puesto). Ordenados por nombre.
     *
     * @return list<array{user: User, role: Role, scope: AssignmentScope}>
     */
    public function holders(Permission $permission, Target $target): array
    {
        return $this->holdersByKey($permission, ['target' => $target])['target'];
    }

    /**
     * holders() para varios objetivos a la vez (un tablero con un renglón por departamento): los candidatos se leen
     * una vez y cada objetivo se decide con la misma regla. Por la llave de cada objetivo.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, Target>  $targets
     * @return array<TKey, list<array{user: User, role: Role, scope: AssignmentScope}>>
     */
    public function holdersByKey(Permission $permission, array $targets): array
    {
        $roles = $this->grants->rolesGranting($permission);
        $holders = array_map(fn (): array => [], $targets);

        if ($roles === [] || $targets === []) {
            return $holders;
        }

        $candidates = User::query()
            ->where('is_active', true)
            ->whereHas('roleAssignments', fn (Builder $assignments) => $assignments->current()->whereIn('role', $roles))
            ->orderBy('name')
            ->get();

        foreach ($candidates as $user) {
            foreach ($targets as $key => $target) {
                $scope = $this->context->forUser($user)->assignmentsAllowing($permission, $target)[0] ?? null;

                if ($scope !== null) {
                    $holders[$key][] = ['user' => $user, 'role' => $scope->role, 'scope' => $scope];
                }
            }
        }

        return $holders;
    }
}
