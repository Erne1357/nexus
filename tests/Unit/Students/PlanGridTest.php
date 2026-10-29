<?php

use App\Modules\Students\Queries\PlanGrid;

/*
| Cálculo de progreso con seriación (tarea 1.4): a partir de la retícula de un plan (materias con sus créditos
| y su seriación) y de las materias que el alumno aprobó, cuántos créditos y materias del plan lleva, cuáles
| le faltan y cuáles ya puede cursar. Una materia se puede cursar si no la ha aprobado y aprobó toda su
| seriación (reglas-de-negocio.md, "Elegibilidad y demanda").
*/

/**
 * Cálculo (A) y Física (F) de primero, Cálculo II (B) pide A, Cálculo III (C) pide B, y Dinámica (D) pide A
 * y F.
 */
function calculusGrid(): PlanGrid
{
    return new PlanGrid(
        credits: ['A' => 5, 'F' => 4, 'B' => 5, 'C' => 5, 'D' => 4],
        seriation: ['B' => ['A'], 'C' => ['B'], 'D' => ['A', 'F']],
    );
}

it('sin nada aprobado le faltan todas y puede cursar solo las que no tienen seriación', function () {
    expect(calculusGrid()->progressFor([]))->toBe([
        'credits_approved' => 0,
        'subjects_approved' => 0,
        'subjects_pending' => ['A', 'F', 'B', 'C', 'D'],
        'subjects_available' => ['A', 'F'],
    ]);
});

it('al aprobar la seriación, la siguiente materia queda disponible, pero no la que sigue después', function () {
    expect(calculusGrid()->progressFor(['A']))->toBe([
        'credits_approved' => 5,
        'subjects_approved' => 1,
        'subjects_pending' => ['F', 'B', 'C', 'D'],
        'subjects_available' => ['F', 'B'],
    ]);
});

it('una materia con dos materias de seriación pide las dos', function () {
    expect(calculusGrid()->progressFor(['A', 'F'])['subjects_available'])->toBe(['B', 'D'])
        ->and(calculusGrid()->progressFor(['F'])['subjects_available'])->toBe(['A']);
});

it('cuenta créditos y materias solo del plan: una aprobada fuera de la retícula no suma', function () {
    expect(calculusGrid()->progressFor(['A', 'Z']))
        ->toMatchArray(['credits_approved' => 5, 'subjects_approved' => 1]);
});

it('con todo aprobado no le falta nada ni tiene qué cursar', function () {
    expect(calculusGrid()->progressFor(['A', 'F', 'B', 'C', 'D']))->toBe([
        'credits_approved' => 23,
        'subjects_approved' => 5,
        'subjects_pending' => [],
        'subjects_available' => [],
    ]);
});

it('una seriación con una materia que ya no está en la retícula no bloquea', function () {
    $grid = new PlanGrid(credits: ['A' => 5, 'B' => 5], seriation: ['B' => ['A', 'X']]);

    expect($grid->progressFor(['A'])['subjects_available'])->toBe(['B']);
});

describe('especialidades', function () {
    /**
     * Tronco común (A) y dos especialidades: Redes (R1, R2) y Software (S1).
     */
    function specialtyGrid(): PlanGrid
    {
        return new PlanGrid(
            credits: ['A' => 5, 'R1' => 4, 'R2' => 4, 'S1' => 4],
            specialtyOf: ['R1' => 'redes', 'R2' => 'redes', 'S1' => 'software'],
        );
    }

    it('mientras no toma ninguna materia de especialidad, cuentan las de todas: puede elegir cualquiera', function () {
        expect(specialtyGrid()->progressFor(['A']))
            ->toMatchArray(['subjects_pending' => ['R1', 'R2', 'S1'], 'subjects_available' => ['R1', 'R2', 'S1']]);
    });

    it('la especialidad sale de lo que aprobó o está cursando: las de las otras ya no cuentan', function (array $approved, array $taken, array $pending) {
        expect(specialtyGrid()->progressFor($approved, $taken)['subjects_pending'])->toBe($pending);
    })->with([
        'aprobó una de Redes' => [['A', 'R1'], [], ['R2']],
        'está cursando una de Software' => [['A'], ['S1'], ['S1']],
    ]);

    it('los créditos de lo aprobado cuentan aunque sea de otra especialidad', function () {
        expect(specialtyGrid()->progressFor(['A', 'R1', 'S1']))
            ->toMatchArray(['credits_approved' => 13, 'subjects_pending' => ['R2']]);
    });
});
