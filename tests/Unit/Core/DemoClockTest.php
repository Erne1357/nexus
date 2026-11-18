<?php

use App\Modules\Core\Demo\DemoClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/*
| El reloj de la demo por puntos: las fechas se guardan al segundo y RA-02 compara «después de», así que entre dos pasos
| la demo deja pasar un segundo. Con el reloj real espera, con un plazo; con el de Carbon congelado lo adelanta.
*/

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('con el reloj congelado lo adelanta un segundo, sin esperar', function () {
    Carbon::setTestNow('2026-11-18 10:00:00');
    $started = microtime(true);

    DemoClock::nextSecond();

    expect(CarbonImmutable::now()->toDateTimeString())->toBe('2026-11-18 10:00:01')
        ->and(Carbon::now()->toDateTimeString())->toBe('2026-11-18 10:00:01')
        ->and(microtime(true) - $started)->toBeLessThan(0.1);
});

it('con el reloj real espera al segundo siguiente y no más de un segundo', function () {
    $second = CarbonImmutable::now()->getTimestamp();
    $started = microtime(true);

    DemoClock::nextSecond();

    expect(CarbonImmutable::now()->getTimestamp())->toBeGreaterThan($second)
        ->and(microtime(true) - $started)->toBeLessThan(DemoClock::MAX_WAIT_SECONDS);
});
