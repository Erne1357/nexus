<?php

namespace App\Modules\Imports\Sii;

/**
 * Lo que dejó una corrida: un reporte por lote, cuántas incidencias se escribieron y cuántos renglones de la
 * exportación no se pudieron leer (no se migran; el detalle por archivo está en las incidencias).
 */
final readonly class SiiMigrationReport
{
    /**
     * @param  list<SiiBatchReport>  $batches
     */
    public function __construct(public array $batches, public int $incidents, public int $unreadable = 0) {}
}
