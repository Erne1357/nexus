<?php

namespace App\Modules\Staffing\Enums;

/**
 * Qué pide una solicitud de cambio (docs/dominio/modelo-de-datos.md, `change_requests.kind`).
 */
enum ChangeRequestKind: string
{
    case Schedule = 'schedule';
    case Room = 'room';

    public function label(): string
    {
        return match ($this) {
            self::Schedule => 'Horario',
            self::Room => 'Salón',
        };
    }
}
