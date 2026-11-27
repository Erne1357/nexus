<?php

namespace App\Modules\Core\Enums;

/**
 * Estado de un correo del registro (CF-S8): salió, falló o la guardia lo bloqueó porque su destino no es institucional y
 * no es de una excepción declarada (CE-01, GuardMailRecipient).
 */
enum MailStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Enviado',
            self::Failed => 'Fallido',
            self::Blocked => 'Bloqueado',
        };
    }

    /**
     * El tono de su insignia (DESIGN.md, «Insignias de estado»).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Sent => 'success',
            self::Failed => 'error',
            self::Blocked => 'warning',
        };
    }
}
