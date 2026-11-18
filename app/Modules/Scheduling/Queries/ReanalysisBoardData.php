<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Queries\ReanalysisDiff;

/**
 * Lo que cuesta leer para pintar «Re-análisis de la carga» y no depende de los filtros de la pantalla (#620): el diff de
 * demanda (con los grupos de cada bloque), el umbral de RA-04 y lo que ya cambió contra la versión vigente. Se arma una vez
 * por petición (ReanalysisBoardQuery::data()) y los filtros, las cifras y los bloques salen de él sin volver a la base.
 */
final readonly class ReanalysisBoardData
{
    /**
     * @param  array{diff: array<string, mixed>, entries: list<array{kind: string, icon: string, subject: string, subjectName: string, group: string, campus: ?string, title: string, detail: string}>}|null  $changes  nulo si no se pidió o la carga no tiene versión con la que comparar
     */
    public function __construct(
        public ReanalysisDiff $diff,
        public int $threshold,
        public ?array $changes,
    ) {}
}
