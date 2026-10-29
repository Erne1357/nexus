<?php

namespace App\Modules\Students\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Models\Campus;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Los alumnos elegibles por materia de una carrera en un campus (tarea 1.5): los que ya pueden cursarla porque
 * aprobaron toda su seriación y no la han aprobado, según `student_progress` (reglas-de-negocio.md,
 * "Elegibilidad y demanda"). Solo cuentan los alumnos activos, y solo con el avance calculado con su plan
 * actual: un alumno que cambió de plan no cuenta mientras su avance no se recalcule. Si la carrera tiene varios
 * planes, una materia suma a los alumnos de todos. La usan la propuesta del coordinador y Demand.
 */
final class EligibleStudentsQuery
{
    /**
     * @return array<string, int> Número de alumnos elegibles por id de materia, solo de las que tienen alguno.
     */
    public function countsFor(Career $career, Campus $campus): array
    {
        return Student::query()
            ->join('student_progress', function (JoinClause $join): void {
                $join->on('student_progress.student_id', '=', 'students.id')
                    ->on('student_progress.study_plan_id', '=', 'students.study_plan_id');
            })
            ->crossJoin(DB::raw('jsonb_array_elements_text(student_progress.subjects_available) as available(subject_id)'))
            ->where('students.career_id', $career->id)
            ->where('students.campus_id', $campus->id)
            ->where('students.is_active', true)
            ->where('students.status', StudentStatus::Activo)
            ->groupBy('available.subject_id')
            ->toBase()
            ->selectRaw('available.subject_id, count(*) as eligible')
            ->pluck('eligible', 'subject_id')
            ->all();
    }
}
