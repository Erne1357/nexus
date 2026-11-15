<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve el registro de correos y reenvía invitaciones (CF-S8, CF-03): solo el admin, con `core.mail.view` y
 * `core.user.invite`, candados que no se dan a otro puesto.
 */
final class MailLogPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::MailView);
    }

    /**
     * Reenviar la invitación de acceso de un usuario pendiente.
     */
    public function resendInvitation(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::UserInvite);
    }
}
