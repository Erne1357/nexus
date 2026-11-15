<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Lo que se hace con la cuenta de una persona desde «Personas y puestos»: darla de alta (CF-S3), editar su nombre y su
 * correo, darla de baja (CF-05), reactivarla (CF-06), mandarle el enlace para restablecer su contraseña (CF-08) y «Dar
 * acceso» a un usuario sin contraseña (AC-03). Cada ability es un permiso `core.user.*`, candado de CF-03: no se
 * concede a ningún puesto, así que solo el admin, que tiene todo. Que la cuenta sea de un alumno (CF-S4), la de uno
 * mismo o la del último admin (AZ-S12) lo deciden UserAccountRules y las Actions.
 */
final class UserPolicy
{
    use AuthorizesActor;

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::UserCreate);
    }

    public function update(User $user, User $person): bool
    {
        return $this->actor($user)->allows(CorePermission::UserUpdate);
    }

    public function deactivate(User $user, User $person): bool
    {
        return $this->actor($user)->allows(CorePermission::UserDeactivate);
    }

    public function reactivate(User $user, User $person): bool
    {
        return $this->actor($user)->allows(CorePermission::UserReactivate);
    }

    public function resetPassword(User $user, User $person): bool
    {
        return $this->actor($user)->allows(CorePermission::UserResetPassword);
    }

    public function grantAccess(User $user, User $person): bool
    {
        return $this->actor($user)->allows(CorePermission::UserGrantAccess);
    }
}
