<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Faculty\Models\Teacher;

/**
 * Sin la validación de catálogos ningún departamento valida, así que la primera invitación no se libera: solo se
 * reenvía la de quien ya se invitó.
 */
final class NoInvitationRelease implements InvitationRelease
{
    public function isReleased(Teacher $teacher): bool
    {
        return false;
    }
}
