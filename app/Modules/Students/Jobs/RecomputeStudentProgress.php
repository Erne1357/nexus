<?php

namespace App\Modules\Students\Jobs;

use App\Modules\Students\Actions\RefreshStudentProgress;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recalcula en la cola el avance de todos los alumnos (RefreshStudentProgress). Lo despachan las plantillas de
 * importación `students` y `kardex` al terminar de aplicar un lote: Students no escucha a Imports, porque el
 * mapa de dependencias lo prohíbe (tarea 1.4).
 *
 * Espera uno solo en la cola aunque lleguen varias importaciones seguidas, con un candado que vence en una hora
 * por si el job nunca llega a correr. Si llega otra importación mientras corre, se encola de nuevo, porque el
 * kardex ya cambió, pero no se encima con la corrida en curso: espera a que termine y se reintenta cada 30
 * segundos durante una hora. Recalcular es idempotente, así que reintentar es seguro; un error de verdad lo
 * deja fallido a la primera (`maxExceptions`). Media hora de tiempo máximo, como los jobs de Imports.
 */
final class RecomputeStudentProgress implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function handle(RefreshStudentProgress $refresh): void
    {
        $refresh->handle();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('student-progress'))->releaseAfter(30)->expireAfter($this->timeout)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }
}
