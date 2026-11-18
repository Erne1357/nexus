<?php

use App\Modules\AcademicLoad\Rules\ProposalAnomalies;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;

/*
| Anomalías de una fila de la propuesta (confirmado el 2 de noviembre de 2026): se marcan sin bloquear el envío.
| Más esperados que elegibles, y grupos con menos alumnos esperados que el mínimo por grupo.
*/

it('no marca una fila normal', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 70, expected: 70, groups: 2))->toBe([]);
});

it('marca más esperados que elegibles', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 98, expected: 105, groups: 3))->toBe(['105 esperados con 98 elegibles']);
});

it('marca grupos con menos alumnos que el mínimo, en singular y plural', function (int $expected, int $groups, string $message) {
    expect((new ProposalAnomalies(10))->for(eligible: 100, expected: $expected, groups: $groups))->toBe([$message]);
})->with([
    'un grupo' => [2, 1, '1 grupo para 2 alumnos'],
    'dos grupos' => [15, 2, '2 grupos para 15 alumnos'],
    'sin alumnos' => [0, 1, '1 grupo para 0 alumnos'],
    'un alumno' => [1, 1, '1 grupo para 1 alumno'],
]);

it('no marca una materia sin grupos pedidos', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 5, expected: 5, groups: 0))->toBe([]);
});

it('marca las dos cosas a la vez', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 3, expected: 5, groups: 1))->toBe(['5 esperados con 3 elegibles', '1 grupo para 5 alumnos']);
});

it('el mínimo exacto no es anomalía', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 40, expected: 20, groups: 2))->toBe([]);
});

it('toma el mínimo del parámetro global y, si falta o es inválido, usa 10', function (mixed $value, int $minimum) {
    expect(ProposalAnomalies::fromValue($value)->minimum)->toBe($minimum);
})->with([
    'sin parámetro' => [null, 10],
    'válido' => [15, 15],
    'cero' => [0, 10],
    'texto' => ['abc', 10],
]);

/*
| Con proyección vigente (confirmado el 17 de noviembre de 2026), los esperados se comparan con lo que explica: los posibles
| (R1) más los que dependen de aprobar lo que cursan hoy y el nuevo ingreso de su fila de la proyección.
*/

it('con proyección, lo que explica son los posibles más los pendientes de seriación y el nuevo ingreso', function () {
    $forecast = new DemandForecast(['pending_prerequisite_students' => 12, 'new_students' => 40]);

    expect(ProposalAnomalies::explained(30, $forecast))->toBe(82)
        ->and(ProposalAnomalies::explained(30, new DemandForecast(['pending_prerequisite_students' => 5, 'new_students' => null])))->toBe(35)
        ->and(ProposalAnomalies::explained(30, null))->toBeNull();
});

it('en primer semestre con el nuevo ingreso corregido, lo que explica cuenta el corregido; en otra materia, el de la proyección', function () {
    $firstSemester = new DemandForecast(['pending_prerequisite_students' => 0, 'new_students' => 0, 'method' => ForecastMethod::FirstSemester]);
    $formula = new DemandForecast(['pending_prerequisite_students' => 4, 'new_students' => null, 'method' => ForecastMethod::Formula]);

    expect(ProposalAnomalies::explained(0, $firstSemester, 35))->toBe(35)
        ->and(ProposalAnomalies::explained(0, $firstSemester))->toBe(0)
        ->and(ProposalAnomalies::explained(20, $formula, 35))->toBe(24);
});

it('con proyección, no marca los esperados que explica aunque pasen de los posibles', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 49, expected: 62, groups: 2, explained: 62))->toBe([]);
});

it('con proyección, marca los esperados que pasan de lo que explica', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 49, expected: 90, groups: 3, explained: 62))->toBe(['90 esperados con 62 que explica la proyección']);
});
