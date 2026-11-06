<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lecturas de los lugares pendientes de la migración del SII (D18 y D19) para la pantalla de Servicios Escolares.
 */
final class PendingPlacementsQuery
{
    /**
     * Los pendientes sin resolver de un plan por clave de materia, con la materia y su especialidad.
     *
     * @return Collection<int, StudyPlanSubjectPending>
     */
    public function forPlan(StudyPlan $plan): Collection
    {
        return StudyPlanSubjectPending::query()
            ->where('study_plan_id', $plan->getKey())
            ->whereNull('resolved_at')
            ->with(['subject.department', 'specialty'])
            ->join('subjects', 'subjects.id', '=', 'study_plan_subject_pendings.subject_id')
            ->orderBy('subjects.code')
            ->select('study_plan_subject_pendings.*')
            ->get();
    }

    /**
     * Cuántos pendientes sin resolver tiene cada plan, por id de plan; los planes sin pendientes no aparecen.
     *
     * @return array<string, int>
     */
    public function countsByPlan(): array
    {
        return StudyPlanSubjectPending::query()
            ->whereNull('resolved_at')
            ->selectRaw('study_plan_id, count(*) as total')
            ->groupBy('study_plan_id')
            ->pluck('total', 'study_plan_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
