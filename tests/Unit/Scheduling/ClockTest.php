<?php

use App\Modules\Scheduling\Validation\Clock;

/*
| Clock: la hora de una sesión como la muestran el editor, la bitácora y los mensajes, `HH:MM`, venga de la base
| (`HH:MM:SS`) o del cliente.
*/

it('deja la hora en HH:MM', function (string $time, string $clock) {
    expect(Clock::of($time))->toBe($clock);
})->with([
    'de la base' => ['07:00:00', '07:00'],
    'ya en HH:MM' => ['13:30', '13:30'],
    'sin cero a la izquierda' => ['7:05', '07:05'],
]);
