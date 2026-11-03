<?php

namespace App\Modules\Core\Policies\Concerns;

use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;

/**
 * Para las policies de todos los módulos: permiso y alcance siempre con ScopedContext. El módulo declara
 * su enum `<Modulo>Permission`, lo registra en `permissions()` de su provider y lo concede por puesto en `grants()`;
 * la Policy solo pregunta por el permiso, nunca por una lista de puestos.
 *
 *     public function update(User $user, Group $group): bool
 *     {
 *         return $this->scope($user)->allows(
 *             SchedulingPermission::GroupUpdate,
 *             Target::of(campus: $group->campus_id, unit: $group->department_id, career: $group->career_id),
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
