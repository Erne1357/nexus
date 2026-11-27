<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use Carbon\CarbonInterface;

/**
 * Horas base por docente, en una sola consulta agregada (sin N+1). Las de un periodo son las plazas
 * vigentes el primer día del periodo, `terms.starts_on` (S1), sumadas sin tope (S2); ambos son supuestos
 * por confirmar. Un docente sin plazas vigentes da 0. La vigencia sale del scope TeacherPosition::currentOn, la misma regla que BaseHours.
 */
final class TeacherBaseHoursQuery
{
    /**
     * @param  list<string>|null  $teacherIds  Solo estos docentes; null, todos los no borrados.
     * @return array<string, int> Horas base por id de docente, en orden de id.
     */
    public function forTerm(Term $term, ?array $teacherIds = null): array
    {
        return $this->onDate($term->starts_on, $teacherIds);
    }

    /**
     * @param  list<string>|null  $teacherIds
     * @return array<string, int>
     */
    public function onDate(CarbonInterface $date, ?array $teacherIds = null): array
    {
        $hours = TeacherPosition::query()
            ->currentOn($date)
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, sum(base_hours) as base_hours');

        return Teacher::query()
            ->leftJoinSub($hours, 'current_positions', 'current_positions.teacher_id', '=', 'teachers.id')
            ->when($teacherIds !== null, fn ($query) => $query->whereIn('teachers.id', $teacherIds))
            ->selectRaw('teachers.id, coalesce(current_positions.base_hours, 0) as base_hours')
            ->orderBy('teachers.id')
            ->pluck('base_hours', 'id')
            ->map(fn ($hours): int => (int) $hours)
            ->all();
    }
}
