<?php

namespace App\Modules\Core\Observability;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;

/**
 * Escucha el ciclo de vida de un job y deja su línea canónica (`job.done` | `job.failed`).
 *
 * shortcut: con la cola `sync` el job corre dentro de la petición, así que comparte (y reinicia) su acumulador y
 * pisa el `module` de la petición en Context; solo pasa en pruebas y en dev, en producción la cola es `redis`. Subir
 * si algún día `sync` se usa en producción.
 */
final class LogJobOutcome
{
    public function processing(JobProcessing $event): void
    {
        // Resuelve al ejecutar y no captura `$this->app`: en Octane el listener sobrevive a la petición que lo registró.
        app(OperationMetrics::class)->start();
        // El framework hidrata antes el Context del request que lo despachó (con su `module` y `livewire`); el módulo
        // del job es el de su clase, así que se sobrescribe aquí.
        Context::add('module', CanonicalLog::moduleOf($event->job->resolveName()));
    }

    public function processed(JobProcessed $event): void
    {
        // Un job que se marca fallido con `fail()` y regresa normal dispara JobFailed y luego JobProcessed: una línea.
        if (! $event->job->hasFailed()) {
            app(CanonicalLog::class)->job($event->job, 'done');
        }
    }

    public function failed(JobFailed $event): void
    {
        app(CanonicalLog::class)->job($event->job, 'failed');
    }
}
