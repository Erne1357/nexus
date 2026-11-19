<?php

namespace App\Modules\Staffing\Jobs;

use App\Modules\Staffing\Actions\ChangeRequests\ProposeChangeAlternatives;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Calcula en la cola las alternativas del motor de una solicitud de cambio (CR-02; CLAUDE.md §4.7: con la demo masiva
 * tarda de 0.6 a varios segundos). ProposeChangeAlternatives deja `alternatives` en `computing` con un id de cálculo y lo
 * despacha; este job llama a ProposeChangeAlternatives::compute(), que guarda `ready`, `none` o `failed` solo si ese
 * cálculo sigue siendo el vigente.
 *
 * Un solo intento. No hace falta `ShouldBeUnique`: ProposeChangeAlternatives no pide otro mientras uno está en curso
 * (el estado y el id del cálculo se escriben con los candados), y un job de un cálculo que ya no es el vigente no hace
 * nada. Al empezar marca `started_at`. Si se cae o se vence, failed() deja el cálculo `failed`; si muere sin llegar
 * ahí, el cálculo se da por fallido al pasar STALE_AFTER desde que empezó, o QUEUE_STALE_AFTER desde que se pidió si
 * nunca empezó (ChangeAlternativesStatus::of()), y el jefe lo vuelve a pedir.
 */
final class ComputeChangeAlternatives implements ShouldQueue
{
    use Queueable;

    /** Segundos que puede correr: el motor tiene ProposePlacements::TIME_LIMIT_SECONDS por cada una de sus dos llamadas, más leer la foto y revisar cada alternativa. */
    public const int TIMEOUT = 120;

    /** Un cálculo que empezó hace más que esto sin terminar se da por fallido (el doble del tiempo del job; supuesto por confirmar). */
    public const int STALE_AFTER = 2 * self::TIMEOUT;

    /**
     * Un cálculo que lleva más que esto en la cola sin empezar se da por fallido (supuesto por confirmar): la cola
     * `default` tiene un solo worker y la comparten las corridas del motor (RunSolver, hasta 660 s), así que el margen es
     * amplio.
     */
    public const int QUEUE_STALE_AFTER = 1800;

    public const string FAILED_MESSAGE = 'El motor no pudo calcular las alternativas: vuelve a pedirlas.';

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $changeRequestId, public readonly string $calculationId) {}

    public function handle(ProposeChangeAlternatives $propose): void
    {
        $propose->compute($this->changeRequestId, $this->calculationId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProposeChangeAlternatives::class)->fail($this->changeRequestId, $this->calculationId, self::FAILED_MESSAGE);
    }
}
