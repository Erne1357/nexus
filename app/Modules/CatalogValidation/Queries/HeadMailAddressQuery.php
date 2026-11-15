<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\PasswordResetRules;
use App\Modules\Faculty\Models\Teacher;

/**
 * A qué correo le llegan al jefe los avisos de la validación (CV11, confirmado el 15 de noviembre de 2026): al correo
 * institucional de su ficha de docente (`teachers.institutional_email`, AC-06); si no tiene, al de su usuario si es
 * institucional; si no tiene ninguno, a ninguno (el aviso va solo a la campana). Nunca al personal. Las dos
 * direcciones tienen que ser del dominio institucional (PasswordResetRules::isInstitutionalEmail()). Una ficha inactiva
 * cuenta (confirmado el 15 de noviembre de 2026); una borrada no (supuesto por confirmar).
 */
final class HeadMailAddressQuery
{
    public function __construct(private readonly PasswordResetRules $emails) {}

    public function for(User $user): ?string
    {
        $recordEmail = Teacher::query()->where('user_id', $user->getKey())->value('institutional_email');

        return match (true) {
            $this->emails->isInstitutionalEmail($recordEmail) => mb_strtolower((string) $recordEmail),
            $this->emails->isInstitutionalEmail($user->email) => mb_strtolower((string) $user->email),
            default => null,
        };
    }
}
