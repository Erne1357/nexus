<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Models\User;

/**
 * AC-16 (confirmado el 11 de noviembre de 2026): quién puede restablecer su contraseña por su correo institucional.
 * Un usuario activo, con acceso (con contraseña: el restablecimiento no da acceso a quien no lo tiene, AC-01; supuesto
 * por confirmar) y con correo del dominio `nexus.institutional_email_domain`. El del personal tiene que estar
 * confirmado (`email_verified_at`, lo deja la invitación de AC-08); el de quien acceptsUnverifiedInstitutionalEmail()
 * acepte, no. Lo preguntan SendPasswordResetLink, para mandar el enlace, y PasswordResetTokens, para dejarlo usar.
 *
 * No es final: acceptsUnverifiedInstitutionalEmail() es el punto donde se distingue al alumno del personal.
 */
class PasswordResetRules
{
    public function allows(User $user): bool
    {
        return $user->is_active
            && $user->password !== null
            && $this->hasInstitutionalEmail($user)
            && ($user->email_verified_at !== null || $this->acceptsUnverifiedInstitutionalEmail($user));
    }

    /**
     * Si su correo es una dirección válida que termina en `@` y el dominio institucional, sin importar mayúsculas: un
     * subdominio o un dominio que solo lo contiene no cuenta.
     */
    public function hasInstitutionalEmail(User $user): bool
    {
        $email = mb_strtolower((string) $user->email);
        $domain = mb_strtolower((string) config('nexus.institutional_email_domain'));

        return $domain !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && str_ends_with($email, "@{$domain}");
    }

    /**
     * Si a `$user` le basta su correo institucional sin confirmar. Al personal no: su correo lo captura el jefe y solo
     * queda confirmado cuando el usuario abre la invitación que llegó ahí (AC-08). Al alumno sí (supuesto por
     * confirmar): su correo lo fija la regla del TecNM a partir de su número de control y el enlace solo llega al
     * buzón del dueño. Las cuentas de alumno llegan con su propio PR (1.16, cuentas de alumno con su NIP), que responde
     * aquí por ellas; hasta entonces todo usuario es personal y la respuesta es no.
     */
    public function acceptsUnverifiedInstitutionalEmail(User $user): bool
    {
        return false;
    }
}
