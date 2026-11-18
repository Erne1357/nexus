<?php

namespace App\Modules\Core\Demo;

use Carbon\CarbonImmutable;

/**
 * El reloj de la demo por puntos. Las fechas se guardan al segundo y algunas guardas comparan con «después de» estricto
 * (RA-02: el lote de kárdex después de la publicación y la proyección después del lote): un paso que en la vida real
 * pasa horas después del anterior aquí pasa en el mismo segundo. `nextSecond()` espera, con el reloj de verdad, a que
 * empiece el segundo siguiente; no congela ni adelanta el tiempo, así que ninguna fecha queda en el futuro.
 */
final class DemoClock
{
    public static function nextSecond(): void
    {
        $now = CarbonImmutable::now();

        usleep(max(1, 1_000_000 - (int) $now->format('u')));

        while (CarbonImmutable::now()->getTimestamp() <= $now->getTimestamp()) {
            usleep(10_000);
        }
    }
}
