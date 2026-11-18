<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * De dónde salió una versión publicada de la carga (`load_snapshots.reason`, tarea 8.4; lo muestra el tablero de
 * versiones, 8.10): la primera publicación; una republicación al cabo de un re-análisis (el último cierre de la carga
 * salió de `reanalysis`, RA-01 a RA-08); o una corrección, la republicación después de reabrirla a ajustes (P3). Las
 * versiones publicadas antes de la tarea 8.4 no lo tienen (nulo).
 */
enum SnapshotReason: string
{
    case Published = 'published';
    case Reanalysis = 'reanalysis';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Publicación',
            self::Reanalysis => 'Re-análisis',
            self::Correction => 'Corrección',
        };
    }
}
