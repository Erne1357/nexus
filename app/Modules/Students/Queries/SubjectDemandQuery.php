<?php

namespace App\Modules\Students\Queries;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cuántos alumnos necesitan todavía una materia, para vetar que se desactive
 * (reglas-de-negocio.md, "Elegibilidad y demanda"). La necesita un alumno no borrado, activo
 * (`is_active`), con estatus `activo` o `baja_temporal`, cuyo plan la tiene en su retícula (sin
 * contar los planes que la quitaron) y que no la ha acreditado: sin una fila `aprobada` de ella en su
 * kárdex. No cuentan egresados, bajas definitivas ni alumnos sin estatus (confirmado el 29 de octubre
 * de 2026).
 *
 * Si en un plan es una materia de especialidad, respeta la especialidad del alumno como el avance
 * (confirmado el 29 de octubre de 2026): quien ya aprobó o cursa materias de otra especialidad no la necesita.
 * Para eso usa la retícula del plan (PlanGridQuery) y PlanGrid::progressFor(), la misma regla que
 * `subjects_pending`.
 *
 * Todo sale del kárdex al momento, no de `student_progress`: desactivar una materia justo después de
 * importar el kárdex no espera al recálculo del avance.
 */
final class SubjectDemandQuery
{
    public function __construct(private readonly PlanGridQuery $grids) {}

    /**
     * @return array{total: int, plans: list<string>} cuántos alumnos la necesitan y las claves de sus
     *                                                planes, por clave
     */
    public function needing(string $subjectId): array
    {
        $placements = StudyPlanSubject::query()
            ->where('subject_id', $subjectId)
            ->toBase()
            ->get(['study_plan_id', 'specialty_id']);

        $common = $placements->whereNull('specialty_id')->pluck('study_plan_id')->all();
        $bySpecialty = $placements->whereNotNull('specialty_id')->pluck('study_plan_id')->all();

        /** @var array<string, int> $counts id de plan → alumnos que la necesitan */
        $counts = $common === [] ? [] : $this->candidates($subjectId, $common)
            ->groupBy('study_plan_id')
            ->toBase()
            ->selectRaw('study_plan_id, count(*) as total')
            ->pluck('total', 'study_plan_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        foreach ($bySpecialty as $studyPlanId) {
            $counts[$studyPlanId] = $this->countWithSpecialty($subjectId, $studyPlanId);
        }

        $counts = array_filter($counts);

        return [
            'total' => array_sum($counts),
            'plans' => $counts === [] ? [] : StudyPlan::withTrashed()->whereKey(array_keys($counts))->orderBy('code')->pluck('code')->all(),
        ];
    }

    /**
     * Los alumnos de esos planes que podrían necesitarla: no borrados, activos, con estatus `activo`
     * o `baja_temporal`, y sin acreditarla.
     *
     * @param  list<string>  $studyPlanIds
     * @return Builder<Student>
     */
    private function candidates(string $subjectId, array $studyPlanIds): Builder
    {
        return Student::query()
            ->whereIn('study_plan_id', $studyPlanIds)
            ->where('is_active', true)
            ->whereIn('status', [StudentStatus::Activo, StudentStatus::BajaTemporal])
            ->whereDoesntHave('kardexRecords', fn (Builder $kardex) => $kardex
                ->where('subject_id', $subjectId)
                ->where('result', KardexResult::Aprobada));
    }

    /**
     * En un plan donde es de especialidad: los candidatos a los que PlanGrid todavía se las cuenta
     * pendiente. Su especialidad sale de lo que aprobó o cursa, así que basta leer del kárdex las
     * materias de especialidad del plan.
     */
    private function countWithSpecialty(string $subjectId, string $studyPlanId): int
    {
        $grid = $this->grids->forPlan($studyPlanId);
        $students = $this->candidates($subjectId, [$studyPlanId])->pluck('id');

        if ($students->isEmpty()) {
            return 0;
        }

        /** @var Collection<string, Collection<int, object>> $kardex */
        $kardex = KardexRecord::query()
            ->whereIn('student_id', $this->candidates($subjectId, [$studyPlanId])->select('id'))
            ->whereIn('subject_id', array_keys($grid->specialtyOf))
            ->whereIn('result', [KardexResult::Aprobada, KardexResult::Cursando])
            ->toBase()
            ->get(['student_id', 'subject_id', 'result'])
            ->groupBy('student_id');

        return $students->filter(function (string $student) use ($grid, $kardex, $subjectId): bool {
            $records = $kardex->get($student, collect());
            $progress = $grid->progressFor(
                $records->where('result', KardexResult::Aprobada->value)->pluck('subject_id')->all(),
                $records->where('result', KardexResult::Cursando->value)->pluck('subject_id')->all(),
            );

            return in_array($subjectId, $progress['subjects_pending'], true);
        })->count();
    }
}
