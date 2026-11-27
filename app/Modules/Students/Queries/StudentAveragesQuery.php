<?php

namespace App\Modules\Students\Queries;

use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Collection;

/**
 * Los promedios de un alumno, calculados al leer desde su kárdex y nunca guardados (AC-24, tarea 1.67; reglas-de-negocio.md,
 * "Retícula del alumno"). Solo cuentan los renglones con calificación numérica y resultado aprobada o reprobada; una materia
 * acreditada sin nota (convalidación o revalidación) no cuenta (supuesto por confirmar).
 *
 * - **General**: promedio de la calificación con que acreditó cada materia (una por materia: la del periodo más reciente).
 *   Es el «promedio final alcanzado» del SII.
 * - **Con reprobadas**: promedio de todas las oportunidades calificadas, aprobadas y reprobadas (el «aritmético acumulado»).
 * - **Último periodo**: promedio de los renglones calificados del periodo más reciente que tenga alguno (supuesto por confirmar).
 *
 * Dos decimales; null cuando no hay nada que promediar.
 */
final class StudentAveragesQuery
{
    public function forStudent(Student $student): StudentAverages
    {
        /** @var Collection<int, KardexRecord> $graded */
        $graded = KardexRecord::query()
            ->where('student_id', $student->getKey())
            ->whereIn('result', [KardexResult::Aprobada, KardexResult::Reprobada])
            ->whereNotNull('grade')
            ->get(['subject_id', 'term_code', 'grade', 'result']);

        $accredited = $graded->where('result', KardexResult::Aprobada)
            ->sortByDesc(fn (KardexRecord $row): string => (string) $row->term_code)
            ->unique('subject_id');

        $lastTermCode = $graded->whereNotNull('term_code')->max('term_code');

        return new StudentAverages(
            general: self::mean($accredited),
            withFailed: self::mean($graded),
            lastTerm: $lastTermCode === null ? null : self::mean($graded->where('term_code', $lastTermCode)),
            lastTermCode: $lastTermCode,
        );
    }

    /**
     * @param  Collection<int, KardexRecord>  $rows
     */
    private static function mean(Collection $rows): ?string
    {
        if ($rows->isEmpty()) {
            return null;
        }

        // En centésimas enteras, para no arrastrar error de punto flotante al redondear.
        $cents = $rows->sum(fn (KardexRecord $row): int => (int) round(((float) $row->grade) * 100));

        return number_format(round($cents / $rows->count()) / 100, 2, '.', '');
    }
}
