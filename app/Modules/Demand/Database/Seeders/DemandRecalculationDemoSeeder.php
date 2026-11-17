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
 * (exactamente `$failureShare` de reprobadas, redondeado, elegidas de forma repetible con `$seed`). Después recalcula el avance de los
 * alumnos, que avisa con StudentProgressRefreshed y vuelve a proyectar los periodos abiertos (tarea 5.4; `demo:seed`
 * corre con la cola síncrona). Así cambia la proyección de esas materias y de las que las piden de seriación, y la
 * propuesta ya armada tiene filas donde «la proyección cambió».
 *
 * Lo llama el paso de la demo que arma las propuestas, después de armarlas. Escribe directo en el kárdex, como una
 * importación: es solo para la demo.
 */
final class DemandRecalculationDemoSeeder extends Seeder
{
    public function run(string $term = '20271', int $subjects = 6, float $failureShare = 0.5, int $seed = 2027): void
    {
        $inProgress = TermCode::previousRegular($term);

        $subjectIds = KardexRecord::query()
            ->where('term_code', $inProgress)
            ->where('result', KardexResult::Cursando)
            ->groupBy('subject_id')
            ->orderByRaw('count(*) desc')
            ->orderBy('subject_id')
            ->limit($subjects)
            ->pluck('subject_id')
            ->all();

        $rows = KardexRecord::query()
            ->where('term_code', $inProgress)
            ->where('result', KardexResult::Cursando)
            ->whereIn('subject_id', $subjectIds)
            ->pluck('id')
            ->all();

        usort($rows, fn (string $a, string $b): int => [crc32($a.$seed), $a] <=> [crc32($b.$seed), $b]);
        $failed = array_slice($rows, 0, (int) round(count($rows) * $failureShare));
        $passed = array_slice($rows, count($failed));

        KardexRecord::query()->whereIn('id', $failed)->update(['result' => KardexResult::Reprobada, 'grade' => 55]);
        KardexRecord::query()->whereIn('id', $passed)->update(['result' => KardexResult::Aprobada, 'grade' => 82]);

        app(RefreshStudentProgress::class)->handle();

        $this->command?->info(sprintf('Calificaciones tardías de %s en %d materias: %d aprobadas y %d reprobadas; la proyección se recalculó.', $inProgress, count($subjectIds), count($passed), count($failed)));
    }
}
