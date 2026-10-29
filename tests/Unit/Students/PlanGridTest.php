<?php

use App\Modules\Students\Progress\PlanGrid;

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
