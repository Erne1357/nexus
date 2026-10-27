<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\InterruptImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deja fallido, desde un proceso limpio de la cola, un lote cuyo job se cortó (InterruptImportBatch).
 * Lo despachan ValidateQueuedBatch y ApplyQueuedBatch desde failed().
 */
final class InterruptBatch implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ImportBatch $batch,
        public BatchStatus $running,
        public User $causer,
    ) {}

    public function handle(InterruptImportBatch $interrupt): void
    {
        $interrupt->handle($this->batch, $this->running, $this->causer);
    }
}
