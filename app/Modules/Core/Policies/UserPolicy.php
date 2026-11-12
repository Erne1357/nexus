<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Lo que se hace con la cuenta de una persona desde «Personas y puestos». «Dar acceso» (AC-03) a un usuario sin
 * contraseña es `core.user.grant_access`, que no se concede a ningún puesto: solo el admin, que tiene todo.
 */
final class UserPolicy
{
    use AuthorizesActor;

    public function grantAccess(User $user, User $person): bool
    {
        return $this->actor($user)->allows(CorePermission::UserGrantAccess);
    }
}
