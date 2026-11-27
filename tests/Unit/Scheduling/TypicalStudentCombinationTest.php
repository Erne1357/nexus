<?php

use App\Modules\Scheduling\Rules\TypicalStudentCombination;

/*
| La combinación del alumno tipo (HC-03): un grupo por materia sin empalmes. Cada materia es una lista de grupos y cada
| grupo una lista de franjas [día, inicio, fin], con las horas en `HH:MM` o en minutos.
*/

function slot(int $day, string $start, string $end): array
{
    return ['day' => $day, 'start' => $start, 'end' => $end];
}

it('hay combinación si cada materia tiene un grupo que no choca con los elegidos', function () {
    $options = [
        'calculo' => [[slot(1, '07:00', '09:00')], [slot(1, '09:00', '11:00')]],
        'quimica' => [[slot(1, '07:00', '08:00')]],
        'etica' => [[slot(2, '07:00', '09:00')]],
    ];

    expect(TypicalStudentCombination::exists($options))->toBeTrue()
        ->and(TypicalStudentCombination::find($options))->toBe(['calculo' => 1, 'quimica' => 0, 'etica' => 0]);
});

it('no hay combinación si dos materias de un solo grupo se empalman', function () {
    expect(TypicalStudentCombination::exists([
        'calculo' => [[slot(1, '07:00', '09:00')]],
        'quimica' => [[slot(1, '08:00', '10:00')]],
    ]))->toBeFalse();
});

it('no hay combinación aunque ningún par de grupos únicos choque, si los grupos de varias materias se estorban', function () {
    $options = [
        'a' => [[slot(1, '07:00', '08:00')], [slot(1, '08:00', '09:00')]],
        'b' => [[slot(1, '07:00', '08:00')], [slot(1, '08:00', '09:00')]],
        'c' => [[slot(1, '07:00', '09:00')]],
        'd' => [[slot(3, '07:00', '09:00')]],
    ];

    expect(TypicalStudentCombination::exists($options))->toBeFalse()
        ->and(TypicalStudentCombination::unsatisfiableCore($options))->toBe(['b', 'c']);
});

it('horas contiguas no se empalman y un grupo sin sesiones cabe con todo', function () {
    expect(TypicalStudentCombination::exists([
        'a' => [[slot(1, '07:00', '09:00')]],
        'b' => [[slot(1, '09:00', '10:00')]],
        'c' => [[]],
    ]))->toBeTrue();
});

it('acepta las horas en minutos y sin materias hay combinación', function () {
    expect(TypicalStudentCombination::exists(['a' => [[['day' => 1, 'start' => 420, 'end' => 540]]], 'b' => [[['day' => 1, 'start' => 480, 'end' => 600]]]]))->toBeFalse()
        ->and(TypicalStudentCombination::exists([]))->toBeTrue()
        ->and(TypicalStudentCombination::unsatisfiableCore(['a' => [[slot(1, '07:00', '08:00')]]]))->toBe([]);
});
