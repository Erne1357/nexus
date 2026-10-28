<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\BatchNotReady;
use App\Modules\Imports\Actions\ExportBatchErrors;
use App\Modules\Imports\Actions\InterruptImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aplica en la cola un lote validado (ApplyImportBatch) a nombre de quien lo aplica. Se comporta como
 * ValidateQueuedBatch: un solo intento con su corrida (`run`), termina sin tocar el lote si ya no está
 * validado (dos clics en "aplicar", o un lote que ya terminó o falló), y si falla deja el lote fallido
 * con InterruptImportBatch, que conserva lo que alcanzó a escribirse. La página revisa que el lote esté
 * validado antes de despachar.
 */
final class ApplyQueuedBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Media hora, por debajo del `retry_after` de la conexión (ver ValidateQueuedBatch). */
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public string $run;

    public function __construct(public ImportBatch $batch, public User $user)
    {
        $this->run = (string) Str::uuid7();
    }

    public function handle(ApplyImportBatch $apply, ExportBatchErrors $export): void
    {
        try {
            $batch = $apply->handle($this->batch, $this->user, $this->run);
        } catch (BatchNotReady) {
            // Ya no está validado: otra corrida lo tomó, ya terminó o falló.
            return;
        }

        // Al aplicar, una fila que el catálogo ya no acepta pasa a error: el archivo se rehace.
        $export->handle($batch);
    }

    public function failed(?Throwable $exception): void
    {
        app(InterruptImportBatch::class)->handle($this->batch, $this->run, $this->user);
    }
}
