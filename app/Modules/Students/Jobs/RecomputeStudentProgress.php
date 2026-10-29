<?php

namespace App\Modules\Students\Jobs;

use App\Modules\Students\Actions\RefreshStudentProgress;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recalcula en la cola el avance de todos los alumnos (RefreshStudentProgress). Lo despachan las plantillas de
 * importación `students` y `kardex` al terminar de aplicar un lote: Students no escucha a Imports, porque el
 * mapa de dependencias lo prohíbe (tarea 1.4). Espera uno solo en la cola aunque lleguen varias importaciones
 * seguidas; si llega otra mientras corre, se encola de nuevo, porque el kardex ya cambió. Un solo intento y
 * media hora de tiempo máximo, como los jobs de Imports.
 */
final class RecomputeStudentProgress implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function handle(RefreshStudentProgress $refresh): void
    {
        $refresh->handle();
    }
}
