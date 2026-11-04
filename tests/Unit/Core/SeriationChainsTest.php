<?php

use App\Modules\Core\Support\SeriationChains;

/*
| Las cadenas de seriación de una retícula (DESIGN.md, "Retícula del alumno"), las mismas en la ficha del alumno,
| el editor del plan y la propuesta de carrera: cada grupo de dos o más materias unidas por su seriación, en el
| orden de su primera materia en la retícula.
*/

it('agrupa las materias unidas por su seriación, en el orden de su primera materia', function () {
    $chains = SeriationChains::of(
        ['A', 'B', 'C', 'D', 'E', 'F'],
        ['C' => ['A'], 'F' => ['C'], 'E' => ['B'], 'D' => []],
    );

    expect($chains)->toBe([['A', 'C', 'F'], ['B', 'E']]);
});

it('une dos ramas que piden la misma materia en una sola cadena', function () {
    expect(SeriationChains::of(['A', 'B', 'C'], ['B' => ['A'], 'C' => ['A']]))->toBe([['A', 'B', 'C']]);
});

it('no forma cadena con una materia sola ni con un requisito que no está en la retícula', function () {
    expect(SeriationChains::of(['A', 'B'], ['B' => ['Z'], 'Y' => ['A']]))->toBe([])
        ->and(SeriationChains::of([], []))->toBe([]);
});
