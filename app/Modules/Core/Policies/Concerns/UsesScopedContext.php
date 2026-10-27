<?php

namespace App\Modules\Core\Policies\Concerns;

use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;

/**
 * Para las policies de todos los módulos: rol y alcance siempre con ScopedContext.
 *
 *     public function update(User $user, Group $group): bool
 *     {
 *         return $this->scope($user)->covers(
 *             [Role::JefeDepartamento, Role::Coordinador],
 *             $group->campus_id, department: $group->department_id, career: $group->career_id,
 *         );
 *     }
 */
trait UsesScopedContext
{
    /**
     * El contexto de `$user`, el usuario que recibe la policy: el de la petición si es el
     * autenticado, uno propio si no (Gate::forUser()).
     */
    protected function scope(User $user): ScopedContext
    {
        return app(ScopedContext::class)->forUser($user);
    }
}
