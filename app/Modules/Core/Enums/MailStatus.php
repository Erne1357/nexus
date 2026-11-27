<?php

namespace App\Modules\Core\Enums;

/**
 * Estado de un correo del registro (CF-S8): salió o falló. Un correo que la guardia de CE-01 no dejó salir se anota como
 * fallido con el motivo empezando por BLOCKED_PREFIX, y la pantalla lo muestra como «Bloqueado» (MailLog::$is_blocked).
 * Un estado propio (`blocked`) llega en un release posterior: el anterior solo conoce estos dos y fallaría al leer otro
 * (ADR-0033, ADR-0039).
 */
enum MailStatus: string
{
    /** El principio del error de un correo bloqueado por CE-01 (GuardMailRecipient). */
    public const string BLOCKED_PREFIX = 'Bloqueado (CE-01)';

    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Enviado',
            self::Failed => 'Fallido',
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
        };
    }
}
