<?php

use App\Modules\Core\Support\WeekTime;

/*
| Cómo se lee un día y una hora en todo texto para personas (mensajes, bitácora, avisos): «lunes», «7:00».
*/

it('escribe la hora sin cero a la izquierda, de minutos o de una hora guardada', function () {
    expect(WeekTime::clock(420))->toBe('7:00')
        ->and(WeekTime::clock(13 * 60 + 30))->toBe('13:30')
        ->and(WeekTime::clock(5))->toBe('0:05')
        ->and(WeekTime::clock('07:30:00'))->toBe('7:30')
        ->and(WeekTime::clock('09:00'))->toBe('9:00')
        ->and(WeekTime::clock('21:15'))->toBe('21:15');
});

it('nombra el día de la semana en minúsculas, y el número tal cual si no es de lunes a sábado', function () {
    expect(WeekTime::day(1))->toBe('lunes')
        ->and(WeekTime::day(3))->toBe('miércoles')
        ->and(WeekTime::day(6))->toBe('sábado')
        ->and(WeekTime::day(7))->toBe('7');
});
