<?php

namespace App\Modules\Students\Actions;

use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use App\Modules\Students\Progress\PlanGrid;
use App\Modules\Students\Queries\PlanGridQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula `student_progress` de todos los alumnos (sin los borrados) desde su kardex y la retícula de su
 * plan (PlanGrid), de 500 en 500, una transacción por bloque. Solo `aprobada` aprueba. Escribe por alumno con
 * upsert: volver a calcular reescribe el avance, también si el alumno cambió de plan. Es una tabla derivada
 * que se recalcula completa (decisión de modelado 5), así que no deja bitácora ni avisa: lo que cambió ya lo
 * registró la importación. La corre el job RecomputeStudentProgress.
 */
final class RefreshStudentProgress
{
    private const int CHUNK_STUDENTS = 500;

    public function __construct(private readonly PlanGridQuery $grids) {}

    public function handle(): void
    {
        /** @var array<string, PlanGrid> $grids */
        $grids = [];

        Student::query()
            ->select(['id', 'study_plan_id'])
            ->chunkById(self::CHUNK_STUDENTS, function (Collection $students) use (&$grids): void {
                $approved = KardexRecord::query()
                    ->whereIn('student_id', $students->modelKeys())
                    ->where('result', KardexResult::Aprobada)
                    ->distinct()
                    ->get(['student_id', 'subject_id'])
                    ->groupBy('student_id')
                    ->map(fn (Collection $records): array => $records->pluck('subject_id')->all());

                $now = now();
                $rows = $students->map(function (Student $student) use (&$grids, $approved, $now): array {
                    $grid = $grids[$student->study_plan_id] ??= $this->grids->forPlan($student->study_plan_id);
                    $progress = $grid->progressFor($approved->get($student->id, []));

                    return [
                        'student_id' => $student->id,
                        'study_plan_id' => $student->study_plan_id,
                        'credits_approved' => $progress['credits_approved'],
                        'subjects_approved' => $progress['subjects_approved'],
                        'subjects_pending' => json_encode($progress['subjects_pending']),
                        'subjects_available' => json_encode($progress['subjects_available']),
                        'computed_at' => $now,
                    ];
                })->all();

                DB::transaction(fn () => StudentProgress::query()->upsert(
                    $rows,
                    ['student_id'],
                    ['study_plan_id', 'credits_approved', 'subjects_approved', 'subjects_pending', 'subjects_available', 'computed_at'],
                ));
            });
    }
}
