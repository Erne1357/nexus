<?php

use App\Modules\Faculty\Rules\BaseHours;
use Illuminate\Support\Carbon;

/*
| Horas base en una fecha: suma de las plazas vigentes ese día, sin tope en la suma (S2, supuesto por
| confirmar). Un límite nulo es sin límite y el último día de vigencia cuenta.
*/

/** Un día a medianoche en Ciudad Juárez, como los casts de fecha de la aplicación. */
function day(string $date): Carbon
{
    return Carbon::parse($date, 'America/Ciudad_Juarez');
}

function position(int $hours, ?string $from = null, ?string $to = null): array
{
    return ['base_hours' => $hours, 'valid_from' => $from, 'valid_to' => $to];
}

it('suma dos plazas vigentes', function () {
    $total = BaseHours::onDate([position(20), position(10)], day('2027-01-18'));

    expect($total)->toBe(30);
});

it('ignora una plaza vencida y una futura', function () {
    $total = BaseHours::onDate([
        position(40, '2026-01-01', '2026-12-31'),
        position(40, '2027-02-01'),
        position(20, '2027-01-01'),
    ], day('2027-01-18'));

    expect($total)->toBe(20);
});

it('un límite nulo es sin límite', function () {
    $date = day('2030-06-01');

    expect(BaseHours::onDate([position(10, null, null), position(5, '2027-01-01', null), position(7, null, '2031-01-01')], $date))->toBe(22);
});

it('el último día de vigencia cuenta y el primero también', function () {
    $positions = [position(20, '2027-01-18', '2027-06-11')];

    expect(BaseHours::onDate($positions, day('2027-06-11')))->toBe(20)
        ->and(BaseHours::onDate($positions, day('2027-01-18')))->toBe(20)
        ->and(BaseHours::onDate($positions, day('2027-06-12')))->toBe(0)
        ->and(BaseHours::onDate($positions, day('2027-01-17')))->toBe(0);
});

it('tres plazas de tiempo completo dan 120 horas, sin tope (S2)', function () {
    expect(BaseHours::onDate([position(40), position(40), position(40)], day('2027-01-18')))->toBe(120);
});

it('cuenta el día en Ciudad Juárez aunque la fecha llegue en otra zona', function () {
    $utcMorning = Carbon::parse('2027-01-19 03:00:00', 'UTC');

    expect(BaseHours::onDate([position(20, null, '2027-01-18')], $utcMorning))->toBe(20);
});

it('sin plazas son cero horas', function () {
    expect(BaseHours::onDate([], day('2027-01-18')))->toBe(0);
});
