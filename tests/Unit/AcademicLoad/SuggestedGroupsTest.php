<?php

use App\Modules\AcademicLoad\Rules\SuggestedGroups;

/*
| Grupos sugeridos de una fila de la propuesta de carrera (reglas-de-negocio.md, "Propuesta de carrera",
| confirmado el 2 de noviembre de 2026): ceil(esperados / 35), o / 25 si la materia lleva laboratorio, con el
| tamaño objetivo de `demand.target_group_size`. Sin el parámetro, o con un valor que no es un entero mayor
| que cero, 35 y 25 (supuesto por confirmar).
*/

it('sugiere ceil(esperados / 35) para una materia sin laboratorio', function (int $expected, int $groups) {
    expect((new SuggestedGroups)->for($expected, needsLab: false))->toBe($groups);
})->with([
    'ninguno' => [0, 0],
    'uno' => [1, 1],
    'justo 35' => [35, 1],
    '36' => [36, 2],
    '70' => [70, 2],
    '71' => [71, 3],
]);

it('sugiere ceil(esperados / 25) para una materia con laboratorio', function (int $expected, int $groups) {
    expect((new SuggestedGroups)->for($expected, needsLab: true))->toBe($groups);
})->with([
    'ninguno' => [0, 0],
    'justo 25' => [25, 1],
    '26' => [26, 2],
    '35' => [35, 2],
]);

it('usa los tamaños del parámetro', function () {
    $sizes = SuggestedGroups::fromValue(['default' => 40, 'lab' => 20]);

    expect($sizes->defaultSize)->toBe(40)
        ->and($sizes->labSize)->toBe(20)
        ->and($sizes->for(41, needsLab: false))->toBe(2)
        ->and($sizes->for(41, needsLab: true))->toBe(3);
});

it('sin el parámetro, o con uno que no sirve, usa 35 y 25', function (mixed $value, int $default, int $lab) {
    $sizes = SuggestedGroups::fromValue($value);

    expect([$sizes->defaultSize, $sizes->labSize])->toBe([$default, $lab]);
})->with([
    'sin parámetro' => [null, 35, 25],
    'no es un objeto' => [30, 35, 25],
    'solo el general' => [['default' => 40], 40, 25],
    'solo laboratorio' => [['lab' => 20], 35, 20],
    'cero' => [['default' => 0, 'lab' => 0], 35, 25],
    'negativo' => [['default' => -5, 'lab' => 20], 35, 20],
    'decimal' => [['default' => 30.5, 'lab' => 20], 35, 20],
    'texto' => [['default' => '30', 'lab' => 'veinte'], 35, 25],
]);

it('da el tamaño objetivo de una materia con o sin laboratorio', function () {
    $sizes = SuggestedGroups::fromValue(['default' => 40, 'lab' => 20]);

    expect($sizes->sizeFor(needsLab: false))->toBe(40)
        ->and($sizes->sizeFor(needsLab: true))->toBe(20)
        ->and((new SuggestedGroups)->sizeFor(needsLab: true))->toBe(25);
});
