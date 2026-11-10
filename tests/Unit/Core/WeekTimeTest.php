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

it('pasa una hora guardada a minutos desde la medianoche y unos minutos a HH:MM para datos', function () {
    expect(WeekTime::minutes('07:30'))->toBe(450)
        ->and(WeekTime::minutes('21:15:00'))->toBe(1275)
        ->and(WeekTime::hhmm(450))->toBe('07:30')
        ->and(WeekTime::hhmm(5))->toBe('00:05')
        ->and(WeekTime::hhmm(WeekTime::minutes('13:45:00')))->toBe('13:45');
});
