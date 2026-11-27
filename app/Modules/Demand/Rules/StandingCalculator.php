<?php

namespace App\Modules\Demand\Rules;

use App\Modules\Demand\Enums\GapBucket;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Queries\PlanGrid;

/**
 * Reconstruye, desde el kárdex, lo que un alumno puede cursar al inicio de un periodo (tarea 5.2). `student_progress`
 * solo guarda el avance de hoy, así que las tasas de periodos pasados y la proyección «como si» se calculan aquí, con
 * la misma regla de elegibilidad (PlanGrid::progressFor: seriación, especialidad, alcance y porcentaje de créditos;
 * reglas-de-negocio.md, «Elegibilidad y demanda»):
 *
 * - Cuenta lo de antes del periodo; lo aprobado sin periodo (de periodos que no existen en Nexus) es de antes de todo.
 * - Lo que cursa cuenta como aprobado: «el escenario ideal» (confirmado el 16 de noviembre de 2026). `pendingPrerequisite`
 *   dice qué materias quedan disponibles solo por eso. Quien cursa una materia no es elegible para ella: vuelve como
 *   repetidor esperado.
 * - Su semestre es uno más los semestres ordinarios en los que tiene kárdex, con cualquier resultado. Así se estima
 *   igual hoy que en el pasado; el que trae el SII es solo el de hoy.
 * - De cada materia elegible da su tramo de distancia (GapBucket): su semestre en la retícula menos el del alumno
 *   (DM-03, confirmado el 17 de noviembre de 2026).
 * - «Como si» (`$asOfTerm`): para medir la proyección como si se hubiera corrido durante ese periodo, se ignora lo que
 *   vino después, y lo de ese periodo, con cualquier resultado menos baja, se toma como cursando.
 *
 * Puro: recibe la retícula del plan del alumno y sus filas del kárdex.
 */
final class StandingCalculator
{
    /**
     * @param  iterable<array{subject_id: string, term_code: ?string, result: string}|object{subject_id: string, term_code: ?string, result: string}>  $records
     * @param  string  $termCode  el periodo a cuyo inicio se mira
     * @param  string|null  $asOfTerm  el periodo en curso «como si», anterior a `$termCode`
     */
    public function at(PlanGrid $grid, iterable $records, string $termCode, ?string $asOfTerm = null): StudentStanding
    {
        $approved = [];
        $taking = [];
        $terms = [];

        foreach ($records as $record) {
            $record = (array) $record;
            $term = $record['term_code'];
            $result = $record['result'] instanceof KardexResult ? $record['result']->value : $record['result'];
            $subject = $record['subject_id'];

            if ($term === null) {
                if ($result === KardexResult::Aprobada->value) {
                    $approved[$subject] = true;
                }

                continue;
            }

            if ($term >= $termCode || ($asOfTerm !== null && $term > $asOfTerm)) {
                continue;
            }

            if (TermCode::isRegular($term)) {
                $terms[$term] = true;
            }

            if ($asOfTerm !== null && $term === $asOfTerm) {
                if ($result !== KardexResult::Baja->value) {
                    $taking[$subject] = true;
                }

                continue;
            }

            if ($result === KardexResult::Aprobada->value) {
                $approved[$subject] = true;
            } elseif ($result === KardexResult::Cursando->value) {
                $taking[$subject] = true;
            }
        }

        $taking = array_diff_key($taking, $approved);
        $semester = count($terms) + 1;
        $takingIds = array_keys($taking);
        $ideal = $grid->progressFor(array_keys($approved + $taking), $takingIds, $semester)['subjects_available'];
        $strict = $grid->progressFor(array_keys($approved), $takingIds, $semester)['subjects_available'];

        $gapOf = [];

        foreach ($ideal as $subject) {
            $gapOf[$subject] = GapBucket::of($grid->semesterOf[$subject] ?? $semester, $semester);
        }

        return new StudentStanding(
            eligible: $ideal,
            pendingPrerequisite: array_values(array_diff($ideal, $strict)),
            taking: $takingIds,
            semester: $semester,
            gapOf: $gapOf,
        );
    }
}
