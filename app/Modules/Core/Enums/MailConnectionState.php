<?php

namespace App\Modules\Core\Enums;

/**
 * El estado de la cuenta de envío en la tarjeta de «Correos y colas» (tarea 1.29, CF-S9): faltan variables de Azure en
 * el .env, no hay cuenta conectada, está conectada, o Microsoft ya no la renueva y hay que reconectarla.
 */
enum MailConnectionState: string
{
    case Unconfigured = 'unconfigured';
    case Disconnected = 'disconnected';
    case Connected = 'connected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Unconfigured => 'Faltan variables',
            self::Disconnected => 'Sin conectar',
            self::Connected => 'Conectada',
            self::Expired => 'Venció',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Unconfigured => StatusTone::Warning,
            self::Disconnected => StatusTone::Neutral,
            self::Connected => StatusTone::Success,
            self::Expired => StatusTone::Error,
        };
    }
}
