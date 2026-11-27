<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Rules\TeachingHours;
use Carbon\CarbonInterface;

/**
 * Horas frente a grupo por docente (PL-01), en una sola consulta de plazas (sin N+1). Quien tiene plaza administrativa (apoyo y asistencia, PL-03) no se mide con esta cuenta (administrativeForTerm). Las de un periodo salen de las
 * plazas vigentes el primer día del periodo (`terms.starts_on`), como TeacherBaseHoursQuery. Solo trae a los docentes
 * con al menos una plaza vigente: quien no aparece no tiene plazas en Nexus (quien llama decide qué hacer con él).
 */
final class TeacherTeachingHoursQuery
{
    /**
     * @param  list<string>|null  $teacherIds  Solo estos docentes; null, todos los que tienen plaza.
     * @return array<string, int> Horas frente a grupo por id de docente.
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
        return TeacherPosition::query()
            ->currentOn($date)
            ->when($teacherIds !== null, fn ($query) => $query->whereIn('teacher_id', $teacherIds))
            ->get(['teacher_id', 'kind', 'category', 'base_hours'])
            ->groupBy('teacher_id')
            ->map(fn ($positions): int => TeachingHours::forPositions($positions->map(fn (TeacherPosition $position): array => [
                'kind' => $position->kind, 'category' => $position->category, 'base_hours' => $position->base_hours,
            ])))
            ->all();
    }

    /**
     * PL-03: las horas administrativas (suma de las horas de sus plazas de apoyo y asistencia) de los docentes que tienen
     * al menos una plaza de ese tipo vigente el primer día del periodo, en una sola consulta. Quien no aparece no es de
     * plaza administrativa.
     *
     * @param  list<string>|null  $teacherIds
     * @return array<string, int> Horas administrativas por id de docente.
     */
    public function administrativeForTerm(Term $term, ?array $teacherIds = null): array
    {
        return TeacherPosition::query()
            ->currentOn($term->starts_on)
            ->where('kind', PositionKind::Apoyo->value)
            ->when($teacherIds !== null, fn ($query) => $query->whereIn('teacher_id', $teacherIds))
            ->get(['teacher_id', 'kind', 'base_hours'])
            ->groupBy('teacher_id')
            ->map(fn ($positions): int => (int) TeachingHours::administrativeHours($positions->map(fn (TeacherPosition $position): array => [
                'kind' => $position->kind, 'base_hours' => $position->base_hours,
            ])))
            ->all();
    }
}
