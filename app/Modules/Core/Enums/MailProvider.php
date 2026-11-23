<?php

namespace App\Modules\Core\Enums;

/**
 * Por dónde sale el correo de Nexus cuando no es SMTP (tarea 1.29, ADR-0032): por ahora solo Microsoft 365 con
 * Microsoft Graph y permisos delegados. Es la llave única de `mail_connections.provider`.
 */
enum MailProvider: string
{
    case Microsoft = 'microsoft';

    public function label(): string
    {
        return match ($this) {
            self::Microsoft => 'Microsoft 365',
        };
    }
}
