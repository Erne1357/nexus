<?php

use App\Modules\Demand\Enums\GapBucket;

/*
| La distancia entre la materia y el alumno elegible para ella (DM-03, confirmado el 17 de noviembre de 2026): el
| semestre de la materia en la retícula de su plan menos el semestre estimado del alumno, en cuatro tramos.
*/

it('DM-03: agrupa la distancia en atrasada, la suya, una más y dos o más', function (int $subjectSemester, int $studentSemester, GapBucket $bucket) {
    expect(GapBucket::of($subjectSemester, $studentSemester))->toBe($bucket);
})->with([
    'tres atrás' => [1, 4, GapBucket::Behind],
    'una atrás' => [3, 4, GapBucket::Behind],
    'la suya' => [4, 4, GapBucket::Same],
    'una más' => [5, 4, GapBucket::OneAhead],
    'dos más' => [6, 4, GapBucket::TwoAhead],
    'más de dos (sin alcance)' => [9, 4, GapBucket::TwoAhead],
]);
