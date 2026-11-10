<?php

use App\Modules\Staffing\Rules\ScheduleSummary;

/*
| El resumen del horario de un grupo en la pantalla de asignación: días y horas en una línea («L–V 12:00–13:00»,
| «Ma/Ju 9:00–11:00») y sus horas por semana.
*/

function sessionOf(int $day, string $startsAt, string $endsAt): array
{
    return ['day' => $day, 'starts_at' => $startsAt, 'ends_at' => $endsAt];
}

it('resume cinco días seguidos a la misma hora como «L–V»', function () {
    $summary = ScheduleSummary::of(array_map(fn (int $day): array => sessionOf($day, '12:00:00', '13:00:00'), [1, 2, 3, 4, 5]));

    expect($summary->label())->toBe('L–V 12:00–13:00')
        ->and($summary->hours())->toBe(5.0);
});

it('junta con diagonal los días que no van seguidos', function () {
    $summary = ScheduleSummary::of([sessionOf(4, '09:00:00', '11:00:00'), sessionOf(2, '09:00:00', '11:00:00')]);

    expect($summary->label())->toBe('Ma/Ju 9:00–11:00')
        ->and($summary->hours())->toBe(4.0);
});

it('separa con punto medio los bloques de horas distintas, por su primer día', function () {
    $summary = ScheduleSummary::of([
        sessionOf(5, '07:00:00', '08:00:00'),
        sessionOf(1, '07:00:00', '09:00:00'),
        sessionOf(3, '07:00:00', '09:00:00'),
    ]);

    expect($summary->label())->toBe('L/Mi 7:00–9:00 · V 7:00–8:00')
        ->and($summary->hours())->toBe(5.0);
});

it('usa guion para tres o más días seguidos y cuenta las medias horas', function () {
    $summary = ScheduleSummary::of(array_map(fn (int $day): array => sessionOf($day, '16:00', '17:30'), [1, 2, 3]));

    expect($summary->label())->toBe('L–Mi 16:00–17:30')
        ->and($summary->hours())->toBe(4.5)
        ->and($summary->hoursLabel())->toBe('4.5');
});

it('sin sesiones dice «Sin horario» y cero horas', function () {
    $summary = ScheduleSummary::of([]);

    expect($summary->label())->toBe('Sin horario')
        ->and($summary->hours())->toBe(0.0)
        ->and($summary->hoursLabel())->toBe('0');
});
