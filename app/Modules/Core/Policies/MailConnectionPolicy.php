<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién conecta, desconecta y prueba la cuenta de envío del correo (tarea 1.29, CF-S9): solo el admin, con
 * `core.mail.connect`, candado de `core.mail.*` que no se da a otro puesto (CF-03). Verla en la tarjeta es de quien ve
 * «Correos y colas» (MailLogPolicy::viewAny).
 */
final class MailConnectionPolicy
{
    use AuthorizesActor;

    public function manage(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::MailConnect);
    }
}
