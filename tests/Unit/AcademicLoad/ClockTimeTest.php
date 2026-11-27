<?php

use App\Modules\AcademicLoad\Rules\ClockTime;

/*
| ClockTime: la hora del día como segundos, con `H:i` o `H:i:s`, horas de 0 a 23, con o sin cero inicial.
*/

it('convierte la hora a segundos', function (string $time, int $seconds) {
    expect(ClockTime::toSeconds($time))->toBe($seconds);
})->with([
    'H:i' => ['09:30', 34200],
    'H:i:s' => ['09:30:15', 34215],
    'sin cero inicial' => ['9:05', 32700],
    'medianoche' => ['00:00', 0],
    'último minuto' => ['23:59:59', 86399],
]);

it('rechaza lo que no es una hora válida', function (string $time) {
    expect(ClockTime::isValid($time))->toBeFalse()
        ->and(fn () => ClockTime::toSeconds($time))->toThrow(InvalidArgumentException::class);
})->with(['', 'abc', '24:00', '9', '09:60', '09:00:60', '-1:00', '09:00:00:00', '9:5', ' 09:00']);

it('compara horas por valor y no por texto', function () {
    expect(ClockTime::toSeconds('9:00'))->toBeLessThan(ClockTime::toSeconds('10:00'));
});
