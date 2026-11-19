<?php

namespace App\Modules\Staffing\Enums;

use App\Modules\Staffing\Jobs\ComputeChangeAlternatives;
use Carbon\CarbonImmutable;

/**
 * En qué va el cálculo de las alternativas del motor de una solicitud de cambio (CR-02; `change_requests.alternatives.status`):
 * se está calculando en la cola, listas con al menos una, terminó sin ninguna, o falló (con su motivo en `message`).
 * Un cálculo en `computing` se da por fallido (su job murió o se colgó) si empezó (`started_at`) hace más de
 * ComputeChangeAlternatives::STALE_AFTER segundos o, sin empezar, si espera en la cola desde `requested_at` más de
 * ComputeChangeAlternatives::QUEUE_STALE_AFTER (la cola la comparten las corridas del motor, de hasta 660 s): of() lo
 * dice, se pueden volver a pedir y lo que termine ese job ya no se guarda. isQueued() dice si todavía no empieza.
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
     * Si el cálculo en curso todavía espera en la cola (su job no ha empezado).
     *
     * @param  array<string, mixed>|null  $alternatives
     */
    public static function isQueued(?array $alternatives): bool
    {
        return self::of($alternatives) === self::Computing && ($alternatives['started_at'] ?? null) === null;
    }

    /**
     * @param  array<string, mixed>|null  $alternatives
     */
    private static function isStale(?array $alternatives): bool
    {
        $started = $alternatives['started_at'] ?? null;
        [$since, $seconds] = $started === null
            ? [$alternatives['requested_at'] ?? null, ComputeChangeAlternatives::QUEUE_STALE_AFTER]
            : [$started, ComputeChangeAlternatives::STALE_AFTER];

        return $since === null || CarbonImmutable::parse($since)->lt(now()->subSeconds($seconds));
    }
}
