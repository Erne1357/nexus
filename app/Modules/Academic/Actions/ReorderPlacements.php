<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Rules\PlacementRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Reordena las materias de un semestre de la retícula de un plan (D21, confirmado el 5 de noviembre de 2026):
 * `$placementIds` es el orden nuevo, y tiene que ser exactamente el conjunto de lugares del semestre que se ven en
 * el editor (ni más ni menos, sin repetir); si no, es un error sobre `placements` y no se toca nada. Reescribe las
 * posiciones 1..n y solo las filas que cambian. Quién puede lo decide StudyPlanPolicy (`editGrid`) antes de llamarla.
 *
 * En una transacción lee el plan para compartir y las filas del semestre con bloqueo. Si el orden no cambia, no
 * escribe ni registra. Si cambia, deja una bitácora sobre el plan con el orden anterior y el nuevo, por clave de
 * materia (supuesto por confirmar: una sola entrada por reordenamiento, no una por materia). No avisa con
 * CurriculumChanged: el orden no cambia el avance de nadie.
 *
 * El editor muestra primero el tronco común y luego cada especialidad, y dentro de cada sección este orden
 * (supuesto por confirmar): mover una materia a otra sección no cambia lo que se ve.
 */
final class ReorderPlacements
{
    public function __construct(private readonly PlacementRules $rules) {}

    /**
     * @param  list<string>  $placementIds  los lugares del semestre, en el orden nuevo
     *
     * @throws ValidationException sobre `placements`
     */
    public function handle(StudyPlan $plan, int $semester, array $placementIds, User $user): void
    {
        DB::transaction(function () use ($plan, $semester, $placementIds, $user): void {
            $plan = $this->rules->lockPlan($plan->getKey());

            $current = StudyPlanSubject::query()
                ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
                ->whereNull('subjects.deleted_at')
                ->where('study_plan_subjects.study_plan_id', $plan->getKey())
                ->where('study_plan_subjects.semester', $semester)
                ->orderByRaw('study_plan_subjects.position asc nulls last')
                ->orderBy('subjects.code')
                ->lock('for update of study_plan_subjects')
                ->toBase()
                ->get(['study_plan_subjects.id', 'study_plan_subjects.position', 'subjects.code'])
                ->keyBy('id');

            $ids = array_values($placementIds);
            $expected = $current->keys()->all();

            if (count($ids) !== count($expected) || array_diff($ids, $expected) !== [] || count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages(['placements' => 'El orden no corresponde a las materias del semestre: la retícula cambió, recárgala.']);
            }

            $moved = false;

            foreach ($ids as $index => $id) {
                if ($current[$id]->position !== $index + 1) {
                    StudyPlanSubject::query()->whereKey($id)->update(['position' => $index + 1]);
                    $moved = true;
                }
            }

            if (! $moved) {
                return;
            }

            activity()
                ->performedOn($plan)
                ->causedBy($user)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => ['semester' => $semester, 'order' => array_map(fn (string $id): string => $current[$id]->code, $ids)],
                    'old' => ['semester' => $semester, 'order' => $current->pluck('code')->all()],
                ])
                ->log("Reordenó las materias del semestre {$semester} del plan {$plan->code}");
        });
    }
}
