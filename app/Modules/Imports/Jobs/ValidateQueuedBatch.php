<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\Actions\BatchNotReady;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Valida en la cola un lote recién subido (ValidateImportBatch): un kardex puede tener cientos de miles
 * de filas. Un solo intento, porque la Action no se puede repetir sobre el mismo lote. Si otra corrida
 * ya lo tomó, termina sin tocarlo.
 *
 * Si el job falla, failed() deja el lote fallido con InterruptImportBatch, que no hace nada si la
 * Action ya lo dejó fallido con su motivo. Lo hace por la cola: si el job pasó del tiempo máximo, el
 * worker llama a failed() en el mismo proceso que se quedó a media transacción y que está por morir, y
 * lo que escribiera ahí se perdería. `timeout` tiene que quedar por debajo del `retry_after` de la
 * conexión (config/queue.php): si no, la cola entrega el job otra vez y ese segundo intento lo marca
 * fallido mientras el primero sigue corriendo.
 */
final class ValidateQueuedBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Media hora: un bloque de 500 filas tarda segundos, así que un job que llega aquí se atoró. */
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public ImportBatch $batch) {}

    public function handle(ValidateImportBatch $validate): void
    {
        try {
            $validate->handle($this->batch);
        } catch (BatchNotReady) {
            // Otra corrida lo tomó o ya terminó.
        }
    }

    public function failed(?Throwable $exception): void
    {
        InterruptBatch::dispatch($this->batch, BatchStatus::Validating, $this->batch->uploader);
    }
}
