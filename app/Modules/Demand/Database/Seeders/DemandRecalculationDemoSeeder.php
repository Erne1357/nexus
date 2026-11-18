<?php

namespace App\Modules\Demand\Database\Seeders;

use App\Modules\Demand\Rules\TermCode;
use App\Modules\Students\Actions\RefreshStudentProgress;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use Illuminate\Database\Seeder;

/**
 * Calificaciones tardías para la demo (tarea 5.10, pedido de la 5.5 y la 5.6): en las `$subjects` materias con más
 * alumnos cursándolas en el semestre ordinario anterior al de la demo, lo que se cursa pasa a aprobada o reprobada
 * (exactamente `$failureShare` de reprobadas, redondeado, elegidas de forma repetible con `$seed` por número de control y
 * clave de materia, así que la misma semilla da las mismas reprobadas en cada siembra). Después recalcula el avance de los
 * alumnos, que avisa con StudentProgressRefreshed y vuelve a proyectar los periodos abiertos (tarea 5.4; `demo:seed`
 * corre con la cola síncrona). Así cambia la proyección de esas materias y de las que las piden de seriación, y la
 * propuesta ya armada tiene filas donde «la proyección cambió».
 *
 * Con `$capturedShare` no califica un número fijo de materias sino materias completas, de la que más alumnos tiene a la
 * que menos, hasta llevar capturada esa parte de lo que se cursa (`subjectsToCapture()`): la demo del re-análisis (fase 8,
 * #614) deja así un avance que se ve real («N de M», entre 80 y 90 %). Con `$refresh = false` no recalcula: quien llama
 * registra antes su lote de kárdex, para que la proyección salga después de él (RA-02).
 *
 * Lo llama el paso de la demo que arma las propuestas, después de armarlas. Escribe directo en el kárdex, como una
 * importación: es solo para la demo.
 */
final class DemandRecalculationDemoSeeder extends Seeder
{
    /**
     * Las materias que califica: las `$subjects` con más alumnos cursándolas en el semestre ordinario anterior a `$term`,
     * por número de alumnos y luego por clave de materia (no por id, que cambia en cada siembra). La elección no depende de la semilla (la semilla solo reparte aprobadas y
     * reprobadas), así que la demo de la propuesta puede saber de antemano qué filas quedarán en «Cambió».
     *
     * @return list<string>
     */
    public static function subjectsToGrade(string $term, int $subjects): array
    {
        return KardexRecord::query()
            ->join('subjects', 'subjects.id', '=', 'kardex_records.subject_id')
            ->where('kardex_records.term_code', TermCode::previousRegular($term))
            ->where('kardex_records.result', KardexResult::Cursando)
            ->groupBy('kardex_records.subject_id', 'subjects.code')
            ->orderByRaw('count(*) desc')
            ->orderBy('subjects.code')
            ->limit($subjects)
            ->pluck('kardex_records.subject_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * Las materias completas que hay que calificar para llevar capturada al menos `$share` (de 0 a 1) de las filas que
     * se cursan en el semestre ordinario anterior a `$term`: la de más alumnos primero y, entre iguales, por clave de materia
     * (no por id, que cambia en cada siembra).
     * Las que ya están calificadas cuentan como capturadas: una segunda corrida no califica más.
     *
     * @return list<string>
     */
    public static function subjectsToCapture(string $term, float $share): array
    {
        $inProgress = TermCode::previousRegular($term);
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

    public function run(string $term = '20271', int $subjects = 6, float $failureShare = 0.5, int $seed = 2027, ?float $capturedShare = null, bool $refresh = true): void
    {
        $inProgress = TermCode::previousRegular($term);
        $subjectIds = $capturedShare === null ? self::subjectsToGrade($term, $subjects) : self::subjectsToCapture($term, $capturedShare);

        // Reprobadas repetibles con la semilla: se ordenan por el número de control y la clave de la materia, que son los
        // mismos en cada siembra, y no por el id, que es un uuid nuevo cada vez.
        $rows = KardexRecord::query()
            ->join('students', 'students.id', '=', 'kardex_records.student_id')
            ->join('subjects', 'subjects.id', '=', 'kardex_records.subject_id')
            ->where('kardex_records.term_code', $inProgress)
            ->where('kardex_records.result', KardexResult::Cursando)
            ->whereIn('kardex_records.subject_id', $subjectIds)
            ->toBase()
            ->get(['kardex_records.id', 'students.control_number', 'subjects.code'])
            ->map(fn (object $row): array => ['id' => (string) $row->id, 'key' => "{$row->control_number}|{$row->code}"])
            ->sortBy(fn (array $row): array => [crc32($row['key'].'|'.$seed), $row['key']])
            ->pluck('id')
            ->values()
            ->all();

        $failed = array_slice($rows, 0, (int) round(count($rows) * $failureShare));
        $passed = array_slice($rows, count($failed));

        KardexRecord::query()->whereIn('id', $failed)->update(['result' => KardexResult::Reprobada, 'grade' => 55]);
        KardexRecord::query()->whereIn('id', $passed)->update(['result' => KardexResult::Aprobada, 'grade' => 82]);

        if ($refresh) {
            app(RefreshStudentProgress::class)->handle();
        }

        $this->command?->info(sprintf('Calificaciones tardías de %s en %d materias: %d aprobadas y %d reprobadas%s.', $inProgress, count($subjectIds), count($passed), count($failed), $refresh ? '; la proyección se recalculó' : ''));
    }
}
