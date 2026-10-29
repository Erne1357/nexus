<?php

use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\SubjectStanding;

/*
| Estado de una materia en la retícula del alumno, con los nombres del SII (tarea 1.6): sale de sus filas de
| kardex de esa materia y de si ya puede cursarla según su avance. La oportunidad 1 es ordinaria, la 2
| repetición y la 3 curso especial; una baja no gasta oportunidad (reglas-de-negocio.md, "Retícula del alumno").
*/

/**
 * @return array{result: KardexResult, attempt: int, term: string}
 */
function take(KardexResult $result, int $attempt = 1, string $term = '20253'): array
{
    return ['result' => $result, 'attempt' => $attempt, 'term' => $term];
}

it('da el estado de la materia según su kardex: :dataset', function (array $attempts, bool $available, SubjectStanding $standing) {
    expect(SubjectStanding::of($attempts, $available, latestTerm: '20263'))->toBe($standing);
})->with([
    'aprobada, aunque antes la reprobó' => [[take(KardexResult::Reprobada), take(KardexResult::Aprobada, 2, '20261')], false, SubjectStanding::Acreditada],
    'cursando en el periodo más reciente' => [[take(KardexResult::Cursando, 1, '20263')], true, SubjectStanding::Cursando],
    'cursando de nuevo después de reprobarla' => [[take(KardexResult::Reprobada), take(KardexResult::Cursando, 2, '20263')], true, SubjectStanding::Cursando],
    'cursando en un periodo anterior, sin calificación' => [[take(KardexResult::Cursando, 1, '20261')], true, SubjectStanding::SinCalificar],
    'reprobada una vez' => [[take(KardexResult::Reprobada)], true, SubjectStanding::ReprobadaPrioridad],
    'reprobada en repetición' => [[take(KardexResult::Reprobada), take(KardexResult::Reprobada, 2, '20261')], true, SubjectStanding::ACursoEspecial],
    'reprobada en curso especial' => [[take(KardexResult::Reprobada, 3, '20261')], true, SubjectStanding::CursoEspecialReprobado],
    'una baja no gasta oportunidad' => [[take(KardexResult::Reprobada), take(KardexResult::Baja, 2, '20261')], true, SubjectStanding::ReprobadaPrioridad],
    'solo una baja, y ya puede cursarla' => [[take(KardexResult::Baja)], true, SubjectStanding::PuedeSeleccionarla],
    'sin kardex y ya puede cursarla' => [[], true, SubjectStanding::PuedeSeleccionarla],
    'sin kardex y todavía no puede' => [[], false, SubjectStanding::NoPermitida],
]);

it('cada estado lleva el nombre del SII y su icono', function (SubjectStanding $standing, string $label, string $icon) {
    expect($standing->label())->toBe($label)
        ->and($standing->icon())->toBe($icon);
})->with([
    [SubjectStanding::Acreditada, 'Acreditada', 'o-check-circle'],
    [SubjectStanding::Cursando, 'Cursando', 'o-clock'],
    [SubjectStanding::SinCalificar, 'Sin calificar', 'o-question-mark-circle'],
    [SubjectStanding::ReprobadaPrioridad, 'Reprobada · prioridad', 'o-arrow-path'],
    [SubjectStanding::ACursoEspecial, 'A curso especial', 'o-exclamation-triangle'],
    [SubjectStanding::CursoEspecialReprobado, 'Curso especial reprobado', 'o-x-circle'],
    [SubjectStanding::PuedeSeleccionarla, 'Puede seleccionarla', 'o-arrow-right-circle'],
    [SubjectStanding::NoPermitida, 'No permitida aún', 'o-lock-closed'],
]);
