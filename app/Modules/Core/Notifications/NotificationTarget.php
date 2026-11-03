<?php

namespace App\Modules\Core\Notifications;

/**
 * A dónde lleva abrir una notificación: la dirección de su pantalla o, si ya no se puede abrir, por qué
 * (la pantalla ya no existe, el registro se borró o ya no es del alcance del usuario). Uno de los dos. Con
 * dirección, `switchedRole` dice si para abrirla cambió el perfil de la sesión: la pantalla se carga completa,
 * porque los componentes de la página de antes ya no pueden actuar con el perfil nuevo (ActiveRoleHook).
 */
final readonly class NotificationTarget
{
    private function __construct(public ?string $url, public ?string $problem, public bool $switchedRole = false) {}

    public static function to(string $url, bool $switchedRole = false): self
    {
        return new self($url, null, $switchedRole);
    }

    public static function unavailable(string $problem): self
    {
        return new self(null, $problem);
    }
}
