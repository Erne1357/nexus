<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecturas de los lugares pendientes de la migración del SII (D18 y D19) para la pantalla de Servicios Escolares
 * («Por validar»). Solo cuentan los sin resolver de planes sin borrar (R23). Los de una retícula histórica no son
 * trabajo pendiente y no cuentan en el aviso de la lista de planes, pero su plan los sigue mostrando, por si vuelve un
 * alumno (D18, confirmado el 10 de noviembre de 2026).
 */
final class PendingPlacementsQuery
{
    /**
     * Los pendientes sin resolver de un plan por clave de materia, con la materia (aunque se haya borrado del
     * catálogo: el panel lo dice al validarla) y su especialidad. `$search` busca en la clave o el nombre de la
     * materia con TextSearch.
     *
     * @return Collection<int, StudyPlanSubjectPending>
     */
    public function forPlan(StudyPlan $plan, string $search = ''): Collection
    {
        return $this->unresolved()
            ->where('study_plan_subject_pendings.study_plan_id', $plan->getKey())
            ->with(['subject' => fn ($query) => $query->withTrashed(), 'specialty'])
            ->join('subjects', 'subjects.id', '=', 'study_plan_subject_pendings.subject_id')
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['subjects.code', 'subjects.name'], $search))
            ->orderBy('subjects.code')
            ->select('study_plan_subject_pendings.*')
            ->get();
    }

    /**
     * El pendiente sin resolver `$id` del plan, con su materia (aunque se haya borrado) y su especialidad.
     *
     * @throws ModelNotFoundException<StudyPlanSubjectPending> si no existe, es de otro plan, ya se resolvió o el id no es uuid
     */
    public function pendingOrFail(StudyPlan $plan, string $id): StudyPlanSubjectPending
    {
        return KeyLookup::findOrFail(
            $this->unresolved()->where('study_plan_id', $plan->getKey())->with(['subject' => fn ($query) => $query->withTrashed(), 'specialty']),
            $id,
        );
    }

    /**
     * Cuántos pendientes sin resolver tiene el plan.
     */
    public function countForPlan(StudyPlan $plan): int
    {
        return $this->unresolved()->where('study_plan_id', $plan->getKey())->count();
    }

    /**
     * Cuántos pendientes sin resolver tiene cada plan sin borrar que no es una retícula histórica, por id de plan; los
     * planes sin pendientes no aparecen.
     *
     * @return array<string, int>
     */
    public function countsByPlan(): array
    {
        return $this->unresolved()
            ->whereHas('studyPlan', fn (Builder $query) => $query->where('is_historical', false))
            ->selectRaw('study_plan_id, count(*) as total')
            ->groupBy('study_plan_id')
            ->pluck('total', 'study_plan_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * @return Builder<StudyPlanSubjectPending>
     */
    private function unresolved(): Builder
    {
        return StudyPlanSubjectPending::query()->whereNull('study_plan_subject_pendings.resolved_at');
    }
}
