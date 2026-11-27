<?php

namespace App\Modules\Core\Enums;

use App\Modules\Core\Models\User;

/**
 * Cómo está el acceso de un usuario a Nexus (AC-04). Sin contraseña no tiene acceso (AC-01): **sin acceso** si nunca
 * se le invitó e **invitación enviada** si ya (`access_invited_at`). Con contraseña, **debe cambiar su contraseña**
 * mientras tenga pendiente el cambio obligatorio (la por defecto) y **con acceso** después. Que el usuario esté activo
 * es aparte (`is_active`): uno inactivo con contraseña sigue sin poder entrar.
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
        return self::resolve($user->password !== null, $user->access_invited_at !== null, (bool) $user->must_change_password);
    }

    /**
     * El estado sin leer la contraseña: para las consultas que solo traen si la tiene.
     */
    public static function resolve(bool $hasPassword, bool $invited, bool $mustChangePassword): self
    {
        return match (true) {
            ! $hasPassword && ! $invited => self::None,
            ! $hasPassword => self::Invited,
            $mustChangePassword => self::MustChangePassword,
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
     * Si tiene contraseña: sin ella no entra (AC-01). Que esté activo se pregunta aparte.
     */
    public function hasPassword(): bool
    {
        return $this === self::MustChangePassword || $this === self::Granted;
    }
}
