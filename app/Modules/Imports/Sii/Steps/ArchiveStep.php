<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Imports\Actions\ArchiveSiiExport;
use App\Modules\Imports\Sii\SiiBatchReport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;

/**
 * Archivo del SII original (decisión del 10 de noviembre de 2026, confirmada): guarda la exportación tal cual en el
 * esquema `sii_archivo` con ArchiveSiiExport, sin el NIP ni fotos ni firmas. Corre antes que cualquier otro paso para
 * que el original quede guardado aunque uno posterior se detenga.
 *
 * No pasa por el motor de importación porque no transforma nada: escribe por su cuenta y entrega un reporte por archivo
 * (plantilla `sii_archivo`; filas, válidas y altas = renglones guardados, o los que guardaría en un ensayo, en el que
 * solo lee y cuenta).
 */
final class ArchiveStep implements SiiStep
{
    public function __construct(private readonly ArchiveSiiExport $archive) {}

    public function name(): string
    {
        return 'archivo';
    }

    /**
     * @return iterable<SiiBatchReport>
     */
    public function batches(SiiMigration $migration): iterable
    {
        $export = $migration->export();

        foreach ($this->archive->handle($export, $migration->dryRun()) as $file => $rows) {
            yield new SiiBatchReport(ArchiveSiiExport::SCHEMA, $export->fileName(SiiFile::from($file)), $rows, $rows, 0, 0, $rows, 0);
        }
    }
}
