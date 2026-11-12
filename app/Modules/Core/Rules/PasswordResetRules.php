<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;

/**
 * AC-16 (confirmado el 11 de noviembre de 2026): quién puede restablecer su contraseña por su correo institucional.
 * Un usuario activo, con acceso (con contraseña: el restablecimiento no da acceso a quien no lo tiene, AC-01; supuesto
 * por confirmar) y con correo del dominio `nexus.institutional_email_domain`. El del personal tiene que estar
 * confirmado (`email_verified_at`, lo deja la invitación de AC-08); el del alumno, no
 * (acceptsUnverifiedInstitutionalEmail()). Lo preguntan SendPasswordResetLink, para mandar el enlace, y
 * PasswordResetTokens, para dejarlo usar.
 */
final class PasswordResetRules
{
    public function __construct(private readonly Actor $actor) {}

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
     * Si a `$user` le basta su correo institucional sin confirmar: al alumno sí (supuesto por confirmar), porque su
     * correo lo fija la regla del TecNM a partir de su número de control (AC-13) y el enlace solo llega al buzón del
     * dueño. Alumno es quien tiene algún puesto vigente y ninguno es del personal (Role::isStaff(); hoy, solo `alumno`,
     * AC-10). Al personal no, aunque también sea alumno: su correo lo captura el jefe y solo queda confirmado cuando el
     * usuario abre la invitación que llegó ahí (AC-08).
     */
    public function acceptsUnverifiedInstitutionalEmail(User $user): bool
    {
        $roles = $this->actor->forUser($user)->heldRoles();

        return $roles !== [] && array_all($roles, fn (Role $role): bool => ! $role->isStaff());
    }
}
