<?php

namespace App\Modules\Students\Actions;

use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use App\Modules\Students\Queries\PlanGrid;
use App\Modules\Students\Queries\PlanGridQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula `student_progress` de todos los alumnos (sin los borrados) desde su kardex y la retícula de su
 * plan (PlanGrid), de 500 en 500. Lo aprobado da créditos y materias; lo aprobado y lo que está cursando dicen
 * su especialidad, y su semestre limita hasta dónde puede cursar. Escribe por alumno con un upsert por bloque: volver a calcular reescribe el avance, también
 * si el alumno cambió de plan. Un alumno cuyo plan todavía no tiene retícula se queda sin avance, en lugar de
 * uno en ceros que parecería que ya terminó; al final se borra el de los alumnos borrados. Todo va en una sola
 * transacción: quien lee el avance mientras se recalcula (los elegibles) ve el anterior completo y no una mezcla
 * de bloques, y si el recálculo falla a la mitad, el avance se queda como estaba.
 *
 * Es una tabla derivada que se recalcula completa (decisión de modelado 5), así que no deja bitácora ni avisa:
 * lo que cambió ya lo registró la importación. La corre el job RecomputeStudentProgress.
 */
final class RefreshStudentProgress
{
    private const int CHUNK_STUDENTS = 500;

    public function __construct(private readonly PlanGridQuery $grids) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            $this->refresh();
        });
    }

    private function refresh(): void
    {
        /** @var array<string, PlanGrid> $grids */
        $grids = [];

        Student::query()
            ->select(['id', 'study_plan_id', 'current_semester'])
            ->chunkById(self::CHUNK_STUDENTS, function (Collection $students) use (&$grids): void {
                $kardex = KardexRecord::query()
                    ->whereIn('student_id', $students->modelKeys())
                    ->whereIn('result', [KardexResult::Aprobada, KardexResult::Cursando])
                    ->toBase()
                    ->get(['student_id', 'subject_id', 'result'])
                    ->groupBy('student_id');

                $now = now();
                $rows = [];
                $withoutGrid = [];

                foreach ($students as $student) {
                    $grid = $grids[$student->study_plan_id] ??= $this->grids->forPlan($student->study_plan_id);

                    if ($grid->credits === []) {
                        $withoutGrid[] = $student->id;

                        continue;
                    }

                    $records = $kardex->get($student->id, collect());
                    $progress = $grid->progressFor(
                        $records->where('result', KardexResult::Aprobada->value)->pluck('subject_id')->unique()->values()->all(),
                        $records->where('result', KardexResult::Cursando->value)->pluck('subject_id')->unique()->values()->all(),
                        $student->current_semester,
                    );

                    $rows[] = [
                        'student_id' => $student->id,
                        'study_plan_id' => $student->study_plan_id,
                        'credits_approved' => $progress['credits_approved'],
                        'subjects_approved' => $progress['subjects_approved'],
                        'subjects_pending' => json_encode($progress['subjects_pending']),
                        'subjects_available' => json_encode($progress['subjects_available']),
                        'computed_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    StudentProgress::query()->upsert(
                        $rows,
                        ['student_id'],
                        ['study_plan_id', 'credits_approved', 'subjects_approved', 'subjects_pending', 'subjects_available', 'computed_at'],
                    );
                }

                if ($withoutGrid !== []) {
                    StudentProgress::query()->whereIn('student_id', $withoutGrid)->delete();
                }
            });

        StudentProgress::query()->whereDoesntHave('student')->delete();
    }
}
