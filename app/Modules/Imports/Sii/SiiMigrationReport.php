<?php

namespace App\Modules\Imports\Sii;

/**
 * Lo que dejó una corrida: un reporte por lote y cuántas incidencias se escribieron.
 */
final readonly class SiiMigrationReport
{
    /**
     * @param  list<SiiBatchReport>  $batches
     */
    public function __construct(public array $batches, public int $incidents) {}
}
