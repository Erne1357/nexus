<?php

use App\Modules\AcademicLoad\Rules\ParallelGroups;

/*
| Paralelos de una materia en la bandeja del departamento (reglas-de-negocio.md, "Propuesta de carrera"): los
| grupos que piden todas las carreras contra los que bastan juntando a sus alumnos (confirmado el 2 de noviembre
| de 2026). Por carrera, los grupos completos son floor(esperados / tamaño); los restos de todas se juntan en
| ceil(suma de restos / tamaño) grupos mixtos, y el ahorro es lo pedido menos lo sugerido (supuesto por confirmar).
*/

/**
 * Lo que da la sugerencia, en arreglos para compararlo de una vez.
 *
 * @return array{careers: array<string, array{expected: int, requested: int, full: int, remainder: int}>, mixed: int, mixedShares: array<string, int>, requested: int, suggested: int, saved: int}
 */
function parallelsOf(ParallelGroups $parallels): array
{
    return [
        'careers' => $parallels->careers,
        'mixed' => $parallels->mixed,
        'mixedShares' => $parallels->mixedShares,
        'requested' => $parallels->requested,
        'suggested' => $parallels->suggested,
        'saved' => $parallels->saved(),
    ];
}

it('junta los restos de ISC e IIND en un mixto: piden 4 y bastan 3', function () {
    $parallels = ParallelGroups::suggest(35, [
        'ISC' => ['expected' => 60, 'requested' => 2],
        'IIND' => ['expected' => 40, 'requested' => 2],
    ]);

    expect(parallelsOf($parallels))->toBe([
        'careers' => [
            'ISC' => ['expected' => 60, 'requested' => 2, 'full' => 1, 'remainder' => 25],
            'IIND' => ['expected' => 40, 'requested' => 2, 'full' => 1, 'remainder' => 5],
        ],
        'mixed' => 1,
        'mixedShares' => ['ISC' => 25, 'IIND' => 5],
        'requested' => 4,
        'suggested' => 3,
        'saved' => 1,
    ])
        ->and($parallels->size)->toBe(35)
        ->and($parallels->isMixed())->toBeTrue();
});

it('sin carreras no sugiere nada', function () {
    expect(parallelsOf(ParallelGroups::suggest(35, [])))->toBe([
        'careers' => [],
        'mixed' => 0,
        'mixedShares' => [],
        'requested' => 0,
        'suggested' => 0,
        'saved' => 0,
    ]);
});

it('con cero esperados no sugiere grupos y ahorra todo lo pedido', function () {
    $parallels = ParallelGroups::suggest(35, ['ISC' => ['expected' => 0, 'requested' => 1]]);

    expect(parallelsOf($parallels))->toBe([
        'careers' => ['ISC' => ['expected' => 0, 'requested' => 1, 'full' => 0, 'remainder' => 0]],
        'mixed' => 0,
        'mixedShares' => [],
        'requested' => 1,
        'suggested' => 0,
        'saved' => 1,
    ]);
});

it('con esperados exactos todos los grupos son completos y no hay mixtos', function () {
    $parallels = ParallelGroups::suggest(35, [
        'ISC' => ['expected' => 70, 'requested' => 2],
        'IIND' => ['expected' => 35, 'requested' => 1],
    ]);

    expect(parallelsOf($parallels))->toBe([
        'careers' => [
            'ISC' => ['expected' => 70, 'requested' => 2, 'full' => 2, 'remainder' => 0],
            'IIND' => ['expected' => 35, 'requested' => 1, 'full' => 1, 'remainder' => 0],
        ],
        'mixed' => 0,
        'mixedShares' => [],
        'requested' => 3,
        'suggested' => 3,
        'saved' => 0,
    ])->and($parallels->isMixed())->toBeFalse();
});

it('con una sola carrera el resto va en un grupo de esa carrera, que no es mixto', function () {
    $parallels = ParallelGroups::suggest(35, ['ISC' => ['expected' => 60, 'requested' => 2]]);

    expect(parallelsOf($parallels))->toBe([
        'careers' => ['ISC' => ['expected' => 60, 'requested' => 2, 'full' => 1, 'remainder' => 25]],
        'mixed' => 1,
        'mixedShares' => ['ISC' => 25],
        'requested' => 2,
        'suggested' => 2,
        'saved' => 0,
    ])->and($parallels->isMixed())->toBeFalse();
});

it('con el tamaño de laboratorio, 25, junta los restos de cada carrera', function () {
    $parallels = ParallelGroups::suggest(25, [
        'ISC' => ['expected' => 30, 'requested' => 2],
        'IIND' => ['expected' => 30, 'requested' => 2],
    ]);

    expect(parallelsOf($parallels))->toBe([
        'careers' => [
            'ISC' => ['expected' => 30, 'requested' => 2, 'full' => 1, 'remainder' => 5],
            'IIND' => ['expected' => 30, 'requested' => 2, 'full' => 1, 'remainder' => 5],
        ],
        'mixed' => 1,
        'mixedShares' => ['ISC' => 5, 'IIND' => 5],
        'requested' => 4,
        'suggested' => 3,
        'saved' => 1,
    ]);
});

it('si los restos pasan del tamaño sugiere varios mixtos', function () {
    $parallels = ParallelGroups::suggest(35, [
        'ISC' => ['expected' => 30, 'requested' => 1],
        'IIND' => ['expected' => 30, 'requested' => 1],
        'IGE' => ['expected' => 30, 'requested' => 1],
    ]);

    expect($parallels->mixed)->toBe(3)
        ->and($parallels->mixedShares)->toBe(['ISC' => 30, 'IIND' => 30, 'IGE' => 30])
        ->and($parallels->suggested)->toBe(3)
        ->and($parallels->saved())->toBe(0);

    expect(ParallelGroups::suggest(35, [
        'ISC' => ['expected' => 20, 'requested' => 1],
        'IIND' => ['expected' => 20, 'requested' => 1],
        'IGE' => ['expected' => 20, 'requested' => 1],
    ])->saved())->toBe(1);
});

it('da un ahorro negativo si lo pedido no alcanza para los esperados', function () {
    $parallels = ParallelGroups::suggest(35, ['ISC' => ['expected' => 80, 'requested' => 1]]);

    expect($parallels->suggested)->toBe(3)
        ->and($parallels->saved())->toBe(-2);
});

it('rechaza un tamaño que no es mayor que cero', function (int $size) {
    expect(fn () => ParallelGroups::suggest($size, []))->toThrow(InvalidArgumentException::class);
})->with([0, -35]);
