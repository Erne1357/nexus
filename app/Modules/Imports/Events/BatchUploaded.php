<?php

namespace App\Modules\Imports\Events;

use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se subió un archivo para importar (StoreImportBatch). Sale después del commit. Lo escuchará la
 * tarea 1.2 para despachar la validación en cola.
 */
final class BatchUploaded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ImportBatch $batch,
    ) {}
}
