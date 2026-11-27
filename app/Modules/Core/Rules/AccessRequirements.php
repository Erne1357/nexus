<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\PrivacyNotice;

/**
 * Lo que un usuario que ya entró tiene que hacer antes de usar Nexus. Lo preguntan los middleware del grupo `web`
 * (EnsurePasswordIsChanged y EnsurePrivacyNoticeAccepted), la pantalla de cambio de contraseña, UpdateUserPassword y
 * la respuesta del login. No guarda estado (Octane): Actor es de la petición.
 */
final class AccessRequirements
{
    public function __construct(private readonly Actor $actor) {}

    /**
     * Si debe cambiar su contraseña antes de seguir: la tiene marcada para cambiarla (`must_change_password`, supuesto 2
     * de PROJECT.md) o entra con su NIP del SII (AC-12: el cambio del NIP es obligatorio desde el 26 de noviembre de
     * 2026). Lo segundo sale del origen de la contraseña, así que no hubo que marcar a nadie en la base.
     */
    public static function mustChangePassword(User $user): bool
    {
        return $user->must_change_password || $user->password_origin === PasswordOrigin::SiiNip;
    }

    /**
     * AC-22: si debe aceptar el aviso de privacidad antes de seguir: es solo alumno (Actor::isStudentOnly()) y no ha
     * aceptado la versión vigente (PrivacyNotice::VERSION). El personal no lo acepta por ahora, tampoco quien además de
     * alumno tiene un puesto del personal (supuestos por confirmar). Primero revisa la versión, que no consulta nada.
     */
    public function mustAcceptPrivacyNotice(User $user): bool
    {
        return $user->privacy_notice_version !== PrivacyNotice::VERSION
            && $this->actor->forUser($user)->isStudentOnly();
    }
}
