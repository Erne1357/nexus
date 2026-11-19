<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\Actions\ExportBatchErrors;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rehace en la cola el archivo de errores (ExportBatchErrors) de una aplicación que quedó fallida sin que su job lo
 * hiciera: la que la cola dio por fallida (ApplyQueuedBatch::failed()) y la que se marcó fallida por atorarse
 * (RetryStalledBatch). ApplyImportBatch borra `errors_exported_at` al empezar, y sin esto el asistente esperaría diez
 * minutos un archivo que nadie iba a hacer. Un kárdex mal mapeado trae cientos de miles de filas con error: por eso no
 * se arma en la petición. Repetirlo es seguro: reemplaza el archivo entero.
 */
final class ExportQueuedBatchErrors implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Media hora, como los otros jobs de Imports, por debajo del `retry_after` de la conexión. */
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public ImportBatch $batch) {}

    public function handle(ExportBatchErrors $export): void
    {
        $export->handle($this->batch);
    }
}
