<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use Carbon\CarbonInterface;

/**
 * Horas base por docente, en una sola consulta agregada (sin N+1). Las de un periodo son las plazas
 * vigentes el primer día del periodo, `terms.starts_on` (S1), sumadas sin tope (S2); ambos son supuestos
 * por confirmar. Un docente sin plazas vigentes da 0. La misma regla que BaseHours, en SQL.
 */
final class TeacherBaseHoursQuery
{
    /**
     * @param  list<string>|null  $teacherIds  Solo estos docentes; null, todos los no borrados.
     * @return array<string, int> Horas base por id de docente.
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
        $day = $date->toImmutable()->setTimezone(config('app.timezone'))->toDateString();

        return Teacher::query()
            ->leftJoin('teacher_positions', function ($join) use ($day) {
                $join->on('teacher_positions.teacher_id', '=', 'teachers.id')
                    ->whereNull('teacher_positions.deleted_at')
                    ->where(fn ($query) => $query->whereNull('teacher_positions.valid_from')->orWhere('teacher_positions.valid_from', '<=', $day))
                    ->where(fn ($query) => $query->whereNull('teacher_positions.valid_to')->orWhere('teacher_positions.valid_to', '>=', $day));
            })
            ->when($teacherIds !== null, fn ($query) => $query->whereIn('teachers.id', $teacherIds))
            ->groupBy('teachers.id')
            ->selectRaw('teachers.id, coalesce(sum(teacher_positions.base_hours), 0) as base_hours')
            ->pluck('base_hours', 'id')
            ->map(fn ($hours): int => (int) $hours)
            ->all();
    }
}
