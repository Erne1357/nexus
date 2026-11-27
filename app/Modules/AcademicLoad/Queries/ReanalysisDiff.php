<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Demand\Enums\DemandBalance;
use Carbon\CarbonImmutable;

/**
 * El diff del re-análisis de una carga (tarea 8.2): sus filas, ordenadas por campus, carrera y materia, y cuántas hay de
 * cada clase. `unclassified` cuenta las de primer semestre (RA-09). `snapshotVersion` es la versión publicada contra la
 * que se compara (nula si la carga no tiene ninguna) y `forecastComputedAt` el cálculo más reciente de la proyección
 * (nulo sin proyección). `blockGroups` son los grupos de la versión por materia y campus (llave `materia|campus`, el campus
 * vacío sin salones), cada uno con las carreras que tienen lugares en él entre las que se piden: de ahí sale «en N grupos»
 * de un bloque sin volver a leer la foto.
 */
final readonly class ReanalysisDiff
{
    /**
     * @param  list<ReanalysisRow>  $rows
     * @param  array{shortfall: int, surplus: int, adjusted: int, unclassified: int}  $totals
     * @param  array<string, list<list<string>>>  $blockGroups
     */
    public function __construct(
        public array $rows,
        public array $totals,
        public ?int $snapshotVersion,
        public ?CarbonImmutable $forecastComputedAt,
        public array $blockGroups = [],
    ) {}

    /**
     * Las filas de una clase, o las sin clasificar con null.
     *
     * @return list<ReanalysisRow>
     */
    public function rowsOf(?DemandBalance $balance): array
    {
        return array_values(array_filter($this->rows, fn (ReanalysisRow $row): bool => $row->balance === $balance));
    }
}
