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
    expect(KardexCodes::result($status))->toBe($result);
})->with([
    'A' => ['A', KardexResult::Aprobada],
    'R' => ['R', KardexResult::Reprobada],
    'P, reprobada que se acreditó después' => ['P', KardexResult::Reprobada],
    'minúscula' => ['a', KardexResult::Aprobada],
    'otro' => ['X', null],
    'vacío' => ['', null],
]);

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
