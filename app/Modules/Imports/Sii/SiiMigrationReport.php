<?php

namespace App\Modules\Imports\Sii;

/**
 * Lo que dejó una corrida: un reporte por lote, cuántas incidencias se escribieron y cuántos renglones de la
 * exportación no se pudieron leer (no se migran; el detalle por archivo está en las incidencias).
 *
 * La corrida queda «con diferencias» cuando la verificación contra el SII (paso `verificacion`) encontró algo que no
 * cuadra (las diferencias de sus reportes). `$incidents` no cuenta las de nivel `info` (el resumen de la verificación).
 */
final readonly class SiiMigrationReport
{
    /**
     * @param  list<SiiBatchReport>  $batches
     */
    public function __construct(public array $batches, public int $incidents, public int $unreadable = 0) {}

    /**
     * Lo que la verificación contra el SII encontró que no cuadra: renglones de la historia que no pasaron sin
     * explicación y alumnos completos en el SII e incompletos en Nexus.
     */
    public function differences(): int
    {
        return array_sum(array_map(fn (SiiBatchReport $batch): int => $batch->differences, $this->batches));
    }

    /**
     * Las notas de los reportes para la consola, en orden.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return array_merge(...array_map(fn (SiiBatchReport $batch): array => $batch->notes, $this->batches));
    }
}
