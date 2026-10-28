<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\Actions\BatchNotReady;
use App\Modules\Imports\Actions\ExportBatchErrors;
use App\Modules\Imports\Actions\InterruptImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Valida en la cola un lote recién subido (ValidateImportBatch) y deja listo el CSV de sus filas con
 * error (ExportBatchErrors): un kardex puede tener cientos de miles de filas. Un solo intento, porque la
 * Action no se puede repetir sobre el mismo lote. Si el lote ya no está subido (otra corrida lo tomó,
 * ya terminó o falló), termina sin tocarlo.
 *
 * `run` identifica esta corrida: viaja en el payload, así que es el mismo si la cola entrega el job otra
 * vez, y queda en el lote al empezar. Si el job falla, failed() deja el lote fallido con
 * InterruptImportBatch solo si sigue validándose por esta corrida; no toca uno que la Action ya dejó
 * fallido con su motivo, ni el de otra corrida. Si el job pasó del tiempo máximo, Laravel revierte la
 * transacción que quedó abierta antes de llamar a failed(), porque los jobs fallidos se guardan en la
 * misma base (`queue.failed`).
 *
 * `timeout` tiene que quedar por debajo del `retry_after` de la conexión (config/queue.php): si no, la
 * cola entrega el job otra vez y ese segundo intento lo marca fallido mientras el primero sigue.
 */
final class ValidateQueuedBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Media hora: un bloque de 500 filas tarda segundos, así que un job que llega aquí se atoró. */
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public string $run;

    public function __construct(public ImportBatch $batch)
    {
        $this->run = (string) Str::uuid7();
    }

    public function handle(ValidateImportBatch $validate, ExportBatchErrors $export): void
    {
        try {
            $batch = $validate->handle($this->batch, $this->run);
        } catch (BatchNotReady) {
            // Ya no está subido: otra corrida lo tomó, ya terminó o falló.
            return;
        }

        if ($batch->status === BatchStatus::Validated) {
            $export->handle($batch);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(InterruptImportBatch::class)->handle($this->batch, $this->run, $this->batch->uploader);
    }
}
