<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * `historical_groups.source`: de dónde sale la fila. `sii` es lo migrado del archivo del SII (antes de Nexus);
 * `nexus` es lo que Nexus publicó (la última versión del snapshot de cada carga, desde 20271).
 */
enum HistoricalGroupSource: string
{
    case Sii = 'sii';
    case Nexus = 'nexus';

    public function label(): string
    {
        return match ($this) {
            self::Sii => 'SII',
            self::Nexus => 'Nexus',
        };
    }
}
