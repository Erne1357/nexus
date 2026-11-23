<?php

namespace App\Modules\Core\Support;

/**
 * Si el código corre dentro de un trabajo del worker de la cola (tarea 1.29, ADR-0032): lo marcan JobProcessing de una
 * conexión que no es `sync` y lo quitan JobProcessed, JobExceptionOccurred y JobFailed (CoreServiceProvider). Una
 * petición web, también con Octane o con la cola `sync`, nunca queda marcada. Con eso el transporte `microsoft-graph`
 * decide si puede esperar a que Microsoft deje mandar (en la cola) o debe fallar en el acto (en la web). Es `scoped`:
 * el worker y Octane la olvidan entre trabajos y entre peticiones.
 */
final class QueueJobScope
{
    private bool $inWorkerJob = false;

    public function begin(): void
    {
        $this->inWorkerJob = true;
    }

    public function end(): void
    {
        $this->inWorkerJob = false;
    }

    public function inWorkerJob(): bool
    {
        return $this->inWorkerJob;
    }
}
