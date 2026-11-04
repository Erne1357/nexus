<?php

namespace App\Modules\Core\Policies\Concerns;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;

/**
 * Para las policies de todos los módulos: permiso y alcance siempre con Actor. El módulo declara
 * su enum `<Modulo>Permission`, lo registra en `permissions()` de su provider y lo concede por puesto en `grants()`;
 * la Policy solo pregunta por el permiso, nunca por una lista de puestos.
 *
 *     public function update(User $user, Group $group): bool
 *     {
 *         return $this->actor($user)->allows(
 *             SchedulingPermission::GroupUpdate,
 *             Target::of(campus: $group->campus_id, unit: $group->department_id, career: $group->career_id),
 *         );
 *     }
 */
trait AuthorizesActor
{
    /**
     * El contexto de `$user`, el usuario que recibe la policy: el de la petición si es el
     * autenticado, uno propio si no (Gate::forUser()).
     */
    protected function actor(User $user): Actor
    {
        return app(Actor::class)->forUser($user);
    }
}
