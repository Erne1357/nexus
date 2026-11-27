<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\UserAccountRules;

/**
 * La cuenta de una persona para su ficha en «Personas y puestos» (1.22-C1): el estado de su acceso (AC-04), si es de un
 * alumno (CF-S4) y, con UserAccountRules, por qué no se editan sus datos, no se le restablece la contraseña (CF-08), no
 * la da de baja `$viewer` (CF-05, AZ-S12), no se reactiva (CF-06) o no se le manda la invitación (CF-07).
 */
final class UserAccountQuery
{
    public function __construct(private readonly UserAccountRules $rules) {}

    public function for(User $person, User $viewer): UserAccount
    {
        return new UserAccount(
            person: $person,
            access: UserAccess::of($person),
            isStudent: $this->rules->isStudent($person),
            emailVerified: $person->email_verified_at !== null,
            updateBlocker: $this->rules->updateBlocker($person),
            resetBlocker: $person->is_active ? $this->rules->resetBlocker($person) : null,
            deactivateBlocker: $person->is_active ? $this->rules->deactivationBlocker($person, $viewer) : null,
            reactivateBlocker: $person->is_active ? null : $this->rules->reactivationBlocker($person),
            inviteBlocker: $person->is_active && $person->password === null ? $this->rules->invitationBlocker($person) : null,
            reactivationEmail: $person->is_active ? null : $this->rules->reactivationInviteEmail($person),
        );
    }
}
