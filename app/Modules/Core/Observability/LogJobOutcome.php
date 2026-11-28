<?php

namespace App\Modules\Core\Observability;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Context;

/**
 * Escucha el ciclo de vida de un job y deja su línea canónica: `job.done` | `job.released` | `job.retry` | `job.failed`.
 *
 * Los jobs de la conexión `sync` no escriben línea propia: corren dentro de quien los llamó (una petición o un job de
 * cola; en producción varias notificaciones mandan su canal `database` por `sync`), así que su trabajo ya cuenta en la
 * línea de ese llamador. Si tuvieran la suya, `processing()` reiniciaría el acumulador de la petición y le pondría el
 * módulo del job, y `request.done` saldría con duración, consultas y módulo truncados.
 */
final class LogJobOutcome
{
    public function processing(JobProcessing $event): void
    {
        if ($event->connectionName === 'sync') {
            return;
        }

        // Resuelve al ejecutar y no captura `$this->app`: en Octane el listener sobrevive a la petición que lo registró.
        app(OperationMetrics::class)->start();
        // El framework hidrata antes el Context del request que lo despachó (con su `module` y `livewire`); el módulo
        // del job es el de su clase, así que se sobrescribe aquí.
        Context::add('module', CanonicalLog::moduleOf($event->job->resolveName()));
    }

    public function processed(JobProcessed $event): void
    {
        // Un job que se marca fallido con `fail()` y regresa normal dispara JobFailed y luego JobProcessed: una línea.
        if ($event->connectionName === 'sync' || $event->job->hasFailed()) {
            return;
        }

        // Laravel también dispara JobProcessed cuando el job se liberó (p. ej. WithoutOverlapping): no llegó a correr.
        app(CanonicalLog::class)->job($event->job, $event->job->isReleased() ? 'released' : 'done');
    }

    public function retry(JobReleasedAfterException $event): void
    {
        // Un intento que lanza con reintentos pendientes no dispara JobProcessed ni JobFailed: sin esto no deja línea.
        if ($event->connectionName !== 'sync') {
            app(CanonicalLog::class)->job($event->job, 'retry');
        }
    }

    public function failed(JobFailed $event): void
    {
        if ($event->connectionName !== 'sync') {
            app(CanonicalLog::class)->job($event->job, 'failed');
        }
    }
}
