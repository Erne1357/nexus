<?php

namespace App\Modules\Core\Demo;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * El reloj de la demo por puntos. Las columnas de fechas son `timestamptz(0)` (`timestampTz()` de Laravel, al segundo) y
 * algunas guardas comparan con «después de» estricto (RA-02: el lote de kárdex después de la publicación y la proyección
 * después del lote): un paso que en la vida real pasa horas después del anterior aquí pasa en el mismo segundo.
 * `nextSecond()` deja que empiece el segundo siguiente del reloj de la aplicación:
 *
 * - con el reloj real, espera con `microtime()` (no con `now()`) a que cambie el segundo, sin congelar ni adelantar el
 *   tiempo, así que ninguna fecha queda en el futuro; si en MAX_WAIT_SECONDS el reloj de la aplicación no avanzó, lanza;
 * - con el reloj de Carbon congelado o movido (`freezeTime()`, `travelTo()`, `Carbon::setTestNow()`), esperar no sirve:
 *   lo adelanta un segundo.
 */
final class DemoClock
{
    /** Lo más que espera el reloj real: medio segundo más que un segundo completo. */
    public const float MAX_WAIT_SECONDS = 2.0;

    /**
     * @throws RuntimeException si el reloj de la aplicación no llega al segundo siguiente en MAX_WAIT_SECONDS
     */
    public static function nextSecond(): void
    {
        if (Carbon::hasTestNow() || CarbonImmutable::hasTestNow()) {
            $next = Carbon::now()->addSecond();
            Carbon::setTestNow($next);
            CarbonImmutable::setTestNow($next->toImmutable());

            return;
        }

        $second = CarbonImmutable::now()->getTimestamp();
        $started = microtime(true);
        $deadline = $started + self::MAX_WAIT_SECONDS;

        usleep((int) max(1, (floor($started) + 1 - $started) * 1_000_000));

        while (CarbonImmutable::now()->getTimestamp() <= $second) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(sprintf('El reloj de la aplicación no pasó del segundo %d en %.0f s: la demo necesita fechas en segundos distintos.', $second, self::MAX_WAIT_SECONDS));
            }

            usleep(10_000);
        }
    }
}
