<?php

use App\Modules\Demand\Rules\StandingCalculator;
use App\Modules\Students\Queries\PlanGrid;

/*
| Lo que un alumno puede cursar al inicio de un periodo, reconstruido de su kárdex (tarea 5.2): lo aprobado antes,
| su seriación y su alcance (su semestre y dos más), con su semestre contado como los periodos ordinarios que ya
| cursó más uno. Lo que cursa hoy cuenta como aprobado («el escenario ideal», confirmado el 16 de noviembre de 2026),
| y aparte se dice qué materias le quedan disponibles solo por eso.
*/

/**
 * A (1.º) → B (2.º) → D (3.º), C (1.º) suelta y E (5.º) suelta.
 */
function standingGrid(): PlanGrid
{
    return new PlanGrid(
        credits: ['A' => 5, 'B' => 5, 'C' => 5, 'D' => 5, 'E' => 5],
        seriation: ['B' => ['A'], 'D' => ['B']],
        semesterOf: ['A' => 1, 'B' => 2, 'C' => 1, 'D' => 3, 'E' => 5],
    );
}

/**
 * @return array{subject_id: string, term_code: ?string, result: string}
 */
function standingRow(string $subject, ?string $term, string $result = 'aprobada'): array
{
    return ['subject_id' => $subject, 'term_code' => $term, 'result' => $result];
}

it('un alumno sin kárdex está en primer semestre y puede cursar lo que no pide seriación a su alcance', function () {
    $standing = (new StandingCalculator)->at(standingGrid(), [], '20263');

    expect($standing->semester)->toBe(1)
        ->and($standing->eligible)->toEqualCanonicalizing(['A', 'C'])
        ->and($standing->pendingPrerequisite)->toBe([])
        ->and($standing->taking)->toBe([]);
});

it('DM-10: cuenta su semestre por los periodos ordinarios que ya cursó, sin verano ni lo que viene después', function () {
    $standing = (new StandingCalculator)->at(standingGrid(), [
        standingRow('A', '20253'),
        standingRow('C', '20261', 'reprobada'),
        standingRow('C', '20262'),
        standingRow('B', '20263'),
        standingRow('D', '20271'),
    ], '20271');

    expect($standing->semester)->toBe(4)
        ->and($standing->eligible)->toEqualCanonicalizing(['D', 'E'])
        ->and($standing->taking)->toBe([]);
});

it('DM-10: lo aprobado sin periodo es de antes de todo y no suma semestres', function () {
    $standing = (new StandingCalculator)->at(standingGrid(), [standingRow('A', null)], '20261');

    expect($standing->semester)->toBe(1)
        ->and($standing->eligible)->toEqualCanonicalizing(['B', 'C']);
});

it('DM-01: quien cursa la seriación queda elegible para la siguiente y se marca aparte', function () {
    $standing = (new StandingCalculator)->at(standingGrid(), [
        standingRow('A', '20253'),
        standingRow('C', '20253'),
        standingRow('B', '20261', 'cursando'),
    ], '20263');

    expect($standing->taking)->toBe(['B'])
        ->and($standing->eligible)->toEqualCanonicalizing(['D', 'E'])
        ->and($standing->pendingPrerequisite)->toBe(['D']);
});

it('DM-01: quien cursa la materia no es elegible para ella: vuelve como repetidor', function () {
    $standing = (new StandingCalculator)->at(standingGrid(), [standingRow('A', '20261', 'cursando')], '20263');

    expect($standing->eligible)->not->toContain('A')
        ->and($standing->taking)->toBe(['A']);
});

it('lo reprobado y la baja no aprueban: la materia sigue disponible', function () {
    $standing = (new StandingCalculator)->at(standingGrid(), [
        standingRow('A', '20253', 'reprobada'),
        standingRow('C', '20253', 'baja'),
    ], '20261');

    expect($standing->eligible)->toEqualCanonicalizing(['A', 'C'])
        ->and($standing->semester)->toBe(2);
});

it('respeta el alcance: su semestre y dos más', function () {
    $grid = new PlanGrid(credits: ['A' => 5, 'E' => 5], semesterOf: ['A' => 1, 'E' => 5]);

    expect((new StandingCalculator)->at($grid, [standingRow('A', '20243')], '20251')->eligible)->toBe([])
        ->and((new StandingCalculator)->at($grid, [standingRow('A', '20243'), standingRow('A', '20251', 'baja'), standingRow('A', '20253', 'baja')], '20261')->eligible)->toBe(['E']);
});

it('como si fuera antes: lo del periodo anterior se toma como cursando, con cualquier resultado menos baja', function () {
    $records = [
        standingRow('A', '20253'),
        standingRow('B', '20261', 'reprobada'),
        standingRow('C', '20261'),
        standingRow('E', '20261', 'baja'),
        standingRow('D', '20262'),
    ];

    $standing = (new StandingCalculator)->at(standingGrid(), $records, '20263', asOfTerm: '20261');

    expect($standing->taking)->toEqualCanonicalizing(['B', 'C'])
        ->and($standing->eligible)->toEqualCanonicalizing(['D', 'E'])
        ->and($standing->pendingPrerequisite)->toBe(['D'])
        ->and($standing->semester)->toBe(3);
});
