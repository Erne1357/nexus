<?php

namespace App\Modules\Students\Jobs;

use App\Modules\Students\Actions\RefreshStudentProgress;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recalcula en la cola el avance de todos los alumnos (RefreshStudentProgress), o solo el de los alumnos de un plan si
 * lo despachan con él (CurriculumChanged trae el plan, ronda de validación de noviembre de 2026). Lo despachan las plantillas de
 * importación `students`, `kardex`, `subjects` y `prerequisites` al terminar de aplicar un lote: Students no
 * escucha a Imports, porque el mapa de dependencias lo prohíbe (tareas 1.4 y 1.9).
 *
 * Espera uno solo en la cola (por plan, o el completo) aunque lleguen varias importaciones seguidas, con un candado que vence en una hora
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

    /**
     * @param  ?string  $studyPlanId  el plan cuyos alumnos se recalculan; null recalcula a todos
     */
    public function __construct(public readonly ?string $studyPlanId = null) {}

    /**
     * Un job único por plan, y otro para el recálculo completo: varios cambios seguidos de la misma retícula esperan un
     * solo recálculo de ese plan, sin pisar el de otro plan.
     */
    public function uniqueId(): string
    {
        return $this->studyPlanId ?? 'all';
    }

    public function handle(RefreshStudentProgress $refresh): void
    {
        $refresh->handle($this->studyPlanId);
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
