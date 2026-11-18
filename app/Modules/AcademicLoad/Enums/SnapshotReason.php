<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\StatusTone;

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

    /**
     * El tono de su insignia en el tablero de versiones (DESIGN.md, «Versiones publicadas»): la publicación en éxito, el
     * re-análisis en información y la corrección en advertencia.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Published => StatusTone::Success,
            self::Reanalysis => StatusTone::Info,
            self::Correction => StatusTone::Warning,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Published => 'o-check-circle',
            self::Reanalysis => 'o-arrow-path',
            self::Correction => 'o-wrench-screwdriver',
        };
    }

    /**
     * El motivo de una versión publicada antes de la tarea 8.4, que no lo guardó: la 1 es la publicación; las demás, según
     * a dónde fue la carga al salir de publicada antes de esta versión (`$reopenedTo`): al re-análisis, un re-análisis;
     * si no, una corrección, porque antes del re-análisis (8.1) solo se republicaba al reabrir la carga a ajustes (P3).
     */
    public static function inferredFor(int $version, ?LoadStatus $reopenedTo = null): self
    {
        return match (true) {
            $version === 1 => self::Published,
            $reopenedTo === LoadStatus::Reanalysis => self::Reanalysis,
            default => self::Correction,
        };
    }
}
