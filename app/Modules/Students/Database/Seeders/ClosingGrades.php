<?php

namespace App\Modules\Students\Database\Seeders;

use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;

/**
 * La regla de las calificaciones de cierre de la demo, en un solo lugar para quien las escribe en el kárdex
 * (DemandRecalculationDemoSeeder) y quien las exporta como archivo de importación (`imports:export-demo`): qué materias se
 * califican y cuáles filas salen reprobadas. Vive en Students porque los dos módulos lo tienen a su izquierda. Solo para
 * la demo.
 */
final class ClosingGrades
{
    public const int PASSED_GRADE = 82;

    public const int FAILED_GRADE = 55;

    /**
     * Las materias completas que hay que calificar para llevar capturada al menos `$share` (de 0 a 1) de las filas que se
     * cursan en `$inProgress`: la de más alumnos primero y, entre iguales, por clave de materia (no por id, que cambia en
     * cada siembra). Las que ya están calificadas cuentan como capturadas: una segunda corrida no califica más.
     *
     * @return list<string>
     */
    public static function subjectsToCapture(string $inProgress, float $share): array
    {
        $graded = KardexRecord::query()->where('term_code', $inProgress)->whereIn('result', [KardexResult::Aprobada, KardexResult::Reprobada])->count();
        $pending = KardexRecord::query()
            ->join('subjects', 'subjects.id', '=', 'kardex_records.subject_id')
            ->where('kardex_records.term_code', $inProgress)
            ->where('kardex_records.result', KardexResult::Cursando)
            ->groupBy('kardex_records.subject_id', 'subjects.code')
            ->orderByRaw('count(*) desc')
            ->orderBy('subjects.code')
            ->selectRaw('kardex_records.subject_id, count(*) as total')
            ->toBase()
            ->get();
        $target = (int) ceil(($graded + $pending->sum('total')) * $share);
        $subjects = [];

        foreach ($pending as $row) {
            if ($graded >= $target) {
                break;
            }

            $subjects[] = (string) $row->subject_id;
            $graded += (int) $row->total;
        }

        return $subjects;
    }

    /**
     * Reparte las filas en reprobadas (exactamente `$failureShare`, redondeado) y aprobadas, de forma repetible: se ordenan
     * por `crc32(llave|semilla)` y luego por la llave (número de control y clave de materia, los mismos en cada siembra, no
     * el id, que es un uuid nuevo cada vez) y las primeras reprueban.
     *
     * @param  list<array{id: string, key: string}>  $rows  `key` es «número de control|clave de materia»
     * @return array{failed: list<string>, passed: list<string>} los `id` de cada grupo
     */
    public static function split(array $rows, float $failureShare, int $seed): array
    {
        $ids = collect($rows)
            ->sortBy(fn (array $row): array => [crc32($row['key'].'|'.$seed), $row['key']])
            ->pluck('id')
            ->values()
            ->all();
        $failed = array_slice($ids, 0, (int) round(count($ids) * $failureShare));

        return ['failed' => $failed, 'passed' => array_slice($ids, count($failed))];
    }
}
