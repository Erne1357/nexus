<?php

namespace App\Modules\Staffing\Jobs;

use App\Modules\Staffing\Actions\ChangeRequests\ProposeChangeAlternatives;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Calcula en la cola las alternativas del motor de una solicitud de cambio (CR-02; CLAUDE.md §4.7: con la demo masiva
 * tarda de 0.6 a varios segundos). ProposeChangeAlternatives deja `alternatives` en `computing` con un id de cálculo y lo
 * despacha; este job llama a ProposeChangeAlternatives::compute(), que guarda `ready`, `none` o `failed` solo si ese
 * cálculo sigue siendo el vigente.
 *
 * Uno por solicitud (`ShouldBeUnique`, con un candado que vence a STALE_AFTER) y un solo intento. Si se cae o se vence,
 * failed() deja el cálculo `failed`; si el job muere sin llegar ahí, el cálculo se da por fallido al pasar STALE_AFTER
 * (ChangeAlternativesStatus::of()) y el jefe lo vuelve a pedir.
 */
final class ComputeChangeAlternatives implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Segundos que puede correr: el motor tiene ProposePlacements::TIME_LIMIT_SECONDS por cada una de sus dos llamadas, más leer la foto y revisar cada alternativa. */
    public const int TIMEOUT = 120;

    /** Un cálculo en `computing` más viejo que esto se da por fallido (el doble del tiempo del job; supuesto por confirmar). */
    public const int STALE_AFTER = 2 * self::TIMEOUT;

    public const string FAILED_MESSAGE = 'El motor no pudo calcular las alternativas: vuelve a pedirlas.';

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    public bool $failOnTimeout = true;

    public int $uniqueFor = self::STALE_AFTER;

    public function __construct(public readonly string $changeRequestId, public readonly string $calculationId) {}

    public function uniqueId(): string
    {
        return $this->changeRequestId;
    }

    public function handle(ProposeChangeAlternatives $propose): void
    {
        $propose->compute($this->changeRequestId, $this->calculationId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProposeChangeAlternatives::class)->fail($this->changeRequestId, $this->calculationId, self::FAILED_MESSAGE);
    }
}
