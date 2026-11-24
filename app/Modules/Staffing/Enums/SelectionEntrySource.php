<?php

namespace App\Modules\Staffing\Enums;

/**
 * De dónde viene un renglón de la cola: del CSV de Sistemas o agregado a mano después (sin criterios).
 */
enum SelectionEntrySource: string
{
    case Csv = 'csv';
    case Appended = 'appended';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Appended => 'Agregado',
        };
    }
}
