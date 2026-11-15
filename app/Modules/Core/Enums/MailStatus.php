<?php

namespace App\Modules\Core\Enums;

/**
 * Estado de un correo del registro (CF-S8): salió o falló.
 */
enum MailStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Enviado',
            self::Failed => 'Fallido',
        };
    }
}
