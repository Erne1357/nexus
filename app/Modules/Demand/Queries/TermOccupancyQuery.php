<?php

namespace App\Modules\Demand\Queries;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ocupación real por materia y periodo, contada del kárdex: alumnos distintos con la materia aprobada, reprobada o
 * cursando (la baja no cuenta). Salta las filas del kárdex y los alumnos borrados y las filas sin periodo.
 *
 * El campus y la modalidad son los actuales del alumno (`students.campus_id`, `students.modality_id`), no los que
 * tenía en ese periodo. El kárdex es único por alumno, materia y periodo, así que los conteos por resultado
 * también son de alumnos distintos.
 */
final class TermOccupancyQuery
{
    private const array COUNTED = [KardexResult::Aprobada, KardexResult::Reprobada, KardexResult::Cursando];

    /**
     * La ocupación real de un periodo, por carrera, campus y materia (ordenada por ese orden de llaves). Con
     * `$campus` null cuenta todos los campus, pero sigue separando por campus.
     *
     * @return list<array{career_id: string, campus_id: ?string, subject_id: string, occupancy: Occupancy}>
     */
    public function forTerm(string $termCode, Modality $modality, ?Campus $campus = null): array
    {
        $rows = $this->counted($modality, $campus)
            ->where('kardex_records.term_code', $termCode)
            ->groupBy('students.career_id', 'students.campus_id', 'kardex_records.subject_id')
            ->orderBy('students.career_id')
            ->orderBy('students.campus_id')
            ->orderBy('kardex_records.subject_id')
            ->get(['students.career_id', 'students.campus_id', 'kardex_records.subject_id', ...$this->aggregates()]);

        return $rows->map(fn (object $row): array => [
            'career_id' => $row->career_id,
            'campus_id' => $row->campus_id,
            'subject_id' => $row->subject_id,
            'occupancy' => $this->occupancyOf($row),
        ])->all();
    }

    /**
     * La ocupación de esas materias en los `$terms` periodos anteriores del mismo tipo que el objetivo. Los
     * periodos sin filas no aparecen.
     *
     * @param  list<string>  $subjectIds
     * @return array<string, array<string, array<string, Occupancy>>> materia → periodo → campus → ocupación
     */
    public function history(string $targetTermCode, Modality $modality, array $subjectIds, int $terms): array
    {
        $termCodes = self::sameTypeTermsBefore($targetTermCode, $terms);

        if ($subjectIds === [] || $termCodes === []) {
            return [];
        }

        $rows = $this->counted($modality, null)
            ->whereIn('kardex_records.subject_id', $subjectIds)
            ->whereIn('kardex_records.term_code', $termCodes)
            ->groupBy('kardex_records.subject_id', 'kardex_records.term_code', 'students.campus_id')
            ->get(['kardex_records.subject_id', 'kardex_records.term_code', 'students.campus_id', ...$this->aggregates()]);

        $history = [];

        foreach ($rows as $row) {
            $history[$row->subject_id][$row->term_code][$row->campus_id] = $this->occupancyOf($row);
        }

        return $history;
    }

    /**
     * Los `$terms` periodos del mismo tipo (mismo último dígito) justo antes del objetivo, el más reciente
     * primero: de `20271`, con 4, `20261`, `20251`, `20241` y `20231`.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException si el código no es `AAAAT` con T en 1, 2 o 3
     */
    public static function sameTypeTermsBefore(string $targetTermCode, int $terms): array
    {
        if (preg_match('/^\d{4}[123]$/', $targetTermCode) !== 1) {
            throw new InvalidArgumentException("Código de periodo inválido: {$targetTermCode}");
        }

        $year = (int) substr($targetTermCode, 0, 4);
        $type = substr($targetTermCode, 4);
        $codes = [];

        for ($back = 1; $back <= $terms; $back++) {
            $codes[] = sprintf('%04d', $year - $back).$type;
        }

        return $codes;
    }

    /**
     * Las filas contables del kárdex unidas a su alumno, en la modalidad (y el campus, si se pide).
     */
    private function counted(Modality $modality, ?Campus $campus): Builder
    {
        return KardexRecord::query()
            ->join('students', 'students.id', '=', 'kardex_records.student_id')
            ->whereNull('students.deleted_at')
            ->whereNotNull('kardex_records.term_code')
            ->whereIn('kardex_records.result', array_map(fn (KardexResult $result): string => $result->value, self::COUNTED))
            ->where('students.modality_id', $modality->id)
            ->when($campus !== null, fn ($query) => $query->where('students.campus_id', $campus?->id))
            ->toBase();
    }

    /**
     * @return list<Expression>
     */
    private function aggregates(): array
    {
        return [
            DB::raw('count(distinct kardex_records.student_id) as students'),
            DB::raw("count(*) filter (where kardex_records.result = 'aprobada') as passed"),
            DB::raw("count(*) filter (where kardex_records.result = 'reprobada') as failed"),
            DB::raw("count(*) filter (where kardex_records.result = 'cursando') as in_progress"),
        ];
    }

    private function occupancyOf(object $row): Occupancy
    {
        return new Occupancy((int) $row->students, (int) $row->passed, (int) $row->failed, (int) $row->in_progress);
    }
}
