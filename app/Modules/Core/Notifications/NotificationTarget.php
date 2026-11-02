<?php

namespace App\Modules\Core\Notifications;

/**
 * A dónde lleva abrir una notificación: la dirección de su pantalla o, si ya no se puede abrir, por qué
 * (la pantalla ya no existe, el registro se borró o ya no es del alcance del usuario). Uno de los dos.
 */
final readonly class NotificationTarget
{
    private function __construct(public ?string $url, public ?string $problem) {}

    public static function to(string $url): self
    {
        return new self($url, null);
    }

    public static function unavailable(string $problem): self
    {
        return new self(null, $problem);
    }
}
