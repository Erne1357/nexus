<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\BatchNotReady;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Aplica en la cola un lote validado (ApplyImportBatch) a nombre de quien lo aplica. Se comporta como
 * ValidateQueuedBatch: un solo intento, termina sin tocar el lote si otra corrida ya lo tomó (dos clics
 * en "aplicar"), y si falla deja el lote fallido por la cola con InterruptImportBatch, que conserva lo
 * que alcanzó a escribirse.
 */
final class ApplyQueuedBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Media hora, por debajo del `retry_after` de la conexión (ver ValidateQueuedBatch). */
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public ImportBatch $batch, public User $user) {}

    public function handle(ApplyImportBatch $apply): void
    {
        try {
            $apply->handle($this->batch, $this->user);
        } catch (BatchNotReady) {
            // Otra corrida lo tomó o ya terminó.
        }
    }

    public function failed(?Throwable $exception): void
    {
        InterruptBatch::dispatch($this->batch, BatchStatus::Applying, $this->user);
    }
}
