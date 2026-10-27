<?php

namespace App\Modules\Imports\Events;

use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Terminó la validación de un lote (ValidateImportBatch), haya quedado `validated` o `failed`. Sale
 * después del commit. Lo usará la tarea 1.2 para avisar al admin que ya puede revisar y aplicar.
 */
final class BatchValidated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ImportBatch $batch,
    ) {}
}
