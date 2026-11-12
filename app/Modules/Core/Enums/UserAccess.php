<?php

namespace App\Modules\Core\Enums;

use App\Modules\Core\Models\User;

/**
 * Cómo está el acceso de un usuario a Nexus (AC-04). Sin contraseña no tiene acceso (AC-01): **sin acceso** si nunca
 * se le invitó e **invitación enviada** si ya (`access_invited_at`). Con contraseña, **debe cambiar su contraseña**
 * mientras tenga pendiente el cambio obligatorio (la por defecto) y **con acceso** después. Que el usuario esté activo
 * es aparte (`is_active`).
 */
enum UserAccess: string
{
    case None = 'sin_acceso';
    case Invited = 'invitado';
    case MustChangePassword = 'debe_cambiar';
    case Granted = 'con_acceso';

    /**
     * Necesita `password`, `access_invited_at` y `must_change_password` cargados.
     */
    public static function of(User $user): self
    {
        return match (true) {
            $user->password === null && $user->access_invited_at === null => self::None,
            $user->password === null => self::Invited,
            $user->must_change_password => self::MustChangePassword,
            default => self::Granted,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sin acceso',
            self::Invited => 'Invitación enviada',
            self::MustChangePassword => 'Debe cambiar su contraseña',
            self::Granted => 'Con acceso',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::None => StatusTone::Neutral,
            self::Invited => StatusTone::Info,
            self::MustChangePassword => StatusTone::Warning,
            self::Granted => StatusTone::Success,
        };
    }

    /**
     * Si puede entrar: tiene contraseña (AC-01).
     */
    public function hasAccess(): bool
    {
        return $this === self::MustChangePassword || $this === self::Granted;
    }
}
