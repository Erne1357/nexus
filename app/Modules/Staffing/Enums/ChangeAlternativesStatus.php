<?php

namespace App\Modules\Staffing\Enums;

use App\Modules\Staffing\Jobs\ComputeChangeAlternatives;
use Carbon\CarbonImmutable;

/**
 * En qué va el cálculo de las alternativas del motor de una solicitud de cambio (CR-02; `change_requests.alternatives.status`):
 * se está calculando en la cola, listas con al menos una, terminó sin ninguna, o falló (con su motivo en `message`).
 * Un cálculo que lleva más de ComputeChangeAlternatives::STALE_AFTER segundos en `computing` se da por fallido (su job
 * murió o se colgó): of() lo dice y se pueden volver a pedir.
 */
enum ChangeAlternativesStatus: string
{
    case Computing = 'computing';
    case Ready = 'ready';
    case None = 'none';
    case Failed = 'failed';

    public const string STALE_MESSAGE = 'El cálculo de las alternativas no terminó a tiempo: vuelve a pedirlas.';

    /**
     * El estado de lo guardado, con el cálculo colgado como fallido; nulo si nunca se pidieron.
     *
     * @param  array<string, mixed>|null  $alternatives
     */
    public static function of(?array $alternatives): ?self
    {
        $status = self::tryFrom((string) ($alternatives['status'] ?? ''));

        return $status === self::Computing && self::isStale($alternatives) ? self::Failed : $status;
    }

    /**
     * El motivo de lo guardado para la pantalla (también el de un cálculo colgado); nulo si no hay.
     *
     * @param  array<string, mixed>|null  $alternatives
     */
    public static function messageOf(?array $alternatives): ?string
    {
        return ($alternatives['status'] ?? null) === self::Computing->value && self::isStale($alternatives)
            ? self::STALE_MESSAGE
            : ($alternatives['message'] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $alternatives
     */
    private static function isStale(?array $alternatives): bool
    {
        $requested = $alternatives['requested_at'] ?? null;

        return $requested === null || CarbonImmutable::parse($requested)->lt(now()->subSeconds(ComputeChangeAlternatives::STALE_AFTER));
    }
}
