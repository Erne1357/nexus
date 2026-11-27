<?php

use App\Modules\Imports\Sii\KardexCodes;
use App\Modules\Students\Enums\KardexResult;

/*
| Códigos de `historia_alumno` del SII: la oportunidad por `tipo_evaluacion` (K1, elegida el 9 de noviembre de 2026),
| el resultado por `estatus_materia`, la calificación (K2: una aprobada con 0 queda sin calificación) y el periodo.
*/

it('da la oportunidad por el número del tipo de evaluación', function (string $type, int $attempt) {
    expect(KardexCodes::attempt($type))->toBe($attempt);
})->with([
    'ordinario' => ['O1', 1],
    'regularización' => ['R1', 1],
    'extraordinario' => ['E1', 1],
    'ordinario de repetición' => ['O2', 2],
    'regularización de repetición' => ['R2', 2],
    'extraordinario de repetición' => ['E2', 2],
    'curso especial' => ['CE', 3],
    'examen especial' => ['EE', 3],
    'convalidación' => ['RC', 1],
    'revalidación' => ['RU', 1],
    'acreditación' => ['AC', 1],
    'EA' => ['EA', 1],
    'CG' => ['CG', 1],
    'minúsculas' => ['o2', 2],
]);

it('no conoce otro tipo de evaluación', function (string $type) {
    expect(KardexCodes::attempt($type))->toBeNull();
})->with(['vacío' => [''], 'número' => ['8'], 'otro' => ['XX']]);

it('A es aprobada y R y P son reprobadas', function (string $status, ?KardexResult $result) {
    expect(KardexCodes::result($status, '85', 'O1'))->toBe($result);
})->with([
    'A' => ['A', KardexResult::Aprobada],
    'R' => ['R', KardexResult::Reprobada],
    'P, reprobada que se acreditó después' => ['P', KardexResult::Reprobada],
    'minúscula' => ['a', KardexResult::Aprobada],
    'otro' => ['X', null],
    'vacío' => ['', null],
]);

it('K4: una A con 0 o vacía y tipo de examen es reprobada', function (string $type, string $grade) {
    expect(KardexCodes::result('A', $grade, $type))->toBe(KardexResult::Reprobada)
        ->and(KardexCodes::grade('0', KardexResult::Reprobada))->toBe(0);
})->with(function () {
    foreach (KardexCodes::EXAM_TYPES as $type) {
        foreach (['0', '', '00'] as $grade) {
            yield "{$type} con «{$grade}»" => [$type, $grade];
        }
    }
});

it('K4 mixta: la A con 0 de examen que se volvió a cursar, después o en el mismo periodo, es reprobada aunque sea egresado', function (string $student) {
    $neverRetaken = false; // hay otra línea de la misma materia: no es «nunca se volvió a cursar»

    expect(KardexCodes::result('A', '0', 'O1', KardexCodes::graduateNeverRetaken($student, $neverRetaken)))->toBe(KardexResult::Reprobada);
})->with(['EGR', 'TIT', 'ACT']);

it('K4 mixta: la A con 0 de examen que nunca se volvió a cursar es aprobada sin calificación si el alumno es EGR o TIT', function (string $student) {
    $result = KardexCodes::result('A', '0', 'O1', KardexCodes::graduateNeverRetaken($student, true));

    expect($result)->toBe(KardexResult::Aprobada)
        ->and(KardexCodes::grade('0', $result))->toBeNull();
})->with(['EGR', 'TIT', 'egr', ' TIT ']);

it('K4 mixta: la A con 0 de examen que nunca se volvió a cursar es reprobada para cualquier otro estatus', function (string $student) {
    expect(KardexCodes::result('A', '', 'E1', KardexCodes::graduateNeverRetaken($student, true)))->toBe(KardexResult::Reprobada);
})->with(['ACT', 'BD', 'BT', '']);

it('K4 mixta no toca las convalidaciones ni las calificaciones de 1 a 69', function () {
    expect(KardexCodes::result('A', '50', 'O1', true))->toBe(KardexResult::Aprobada)
        ->and(KardexCodes::result('R', '0', 'O1', true))->toBe(KardexResult::Reprobada)
        ->and(KardexCodes::result('A', '0', 'RC', false))->toBe(KardexResult::Aprobada);
});

it('K2: una A con 0 de convalidación sigue aprobada sin calificación', function (string $type) {
    expect(KardexCodes::result('A', '0', $type))->toBe(KardexResult::Aprobada)
        ->and(KardexCodes::grade('0', KardexResult::Aprobada))->toBeNull();
})->with(['RC', 'RU', 'AC', 'EA', 'CG']);

it('una A de 1 a 69 sigue aprobada con su calificación', function () {
    expect(KardexCodes::result('A', '65', 'O1'))->toBe(KardexResult::Aprobada)
        ->and(KardexCodes::grade('65', KardexResult::Aprobada))->toBe(65);
});

it('guarda la calificación, salvo el 0 de una aprobada (K2)', function (string $value, KardexResult $result, ?int $grade) {
    expect(KardexCodes::grade($value, $result))->toBe($grade);
})->with([
    'aprobada' => ['85', KardexResult::Aprobada, 85],
    'aprobada con 0' => ['0', KardexResult::Aprobada, null],
    'aprobada con menos de 70' => ['60', KardexResult::Aprobada, 60],
    'reprobada con 0' => ['0', KardexResult::Reprobada, 0],
    'vacía' => ['', KardexResult::Reprobada, null],
    'ilegible' => ['NA', KardexResult::Aprobada, null],
    'más de 100' => ['101', KardexResult::Aprobada, null],
]);

it('reconoce una calificación que no se entiende', function (string $value, bool $readable) {
    expect(KardexCodes::readableGrade($value))->toBe($readable);
})->with([
    'número' => ['85', true],
    'cero' => ['0', true],
    'cien' => ['100', true],
    'vacía' => ['', true],
    'letras' => ['NA', false],
    'más de 100' => ['101', false],
    'decimal' => ['85.5', false],
]);

it('acepta solo periodos del SII de 1964 al próximo año', function (string $term, bool $valid) {
    expect(KardexCodes::term($term))->toBe($valid);
})->with([
    'Ene-Jun' => ['20251', true],
    'verano' => ['20252', true],
    'Ago-Dic' => ['20263', true],
    'el primero' => ['19643', true],
    'antes de 1964' => ['19013', false],
    'el próximo año' => [((int) date('Y') + 1).'1', true],
    'dentro de dos años' => [((int) date('Y') + 2).'1', false],
    'año imposible' => ['99993', false],
    'año cero' => ['00011', false],
    'sufijo 4' => ['20254', false],
    'corto' => ['9', false],
    'vacío' => ['', false],
]);
