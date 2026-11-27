<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Academic\Rules\SeriationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia el tipo de un requisito de una materia en su plan: seriación o correquisito; el plan y las dos materias
 * no cambian. Volverlo seriación revisa que no cierre un ciclo (SeriationRules); volverlo correquisito siempre se
 * puede, porque los correquisitos no bloquean. Quién puede lo decide StudyPlanPolicy (`editGrid`) antes de
 * llamarla.
 *
 * En una transacción bloquea el plan para escribir (como AddPrerequisite, por el ciclo), las filas de la retícula
 * de las dos materias para compartir (antes que la seriación, en el mismo orden que RemovePlacement) y vuelve a
 * leer la seriación con bloqueo (`lockForUpdate`), así el tipo anterior de la bitácora es el confirmado aunque
 * `$prerequisite` llegue vieja; una que otra sesión quitó no existe. Si el tipo no cambia, no escribe, no registra
 * ni avisa; si cambia, guarda, deja en la bitácora el tipo anterior y el nuevo y, después del commit, avisa con
 * CurriculumChanged. Deja las dos materias en las relaciones `subject` y `prerequisite`.
 */
final class UpdatePrerequisite
{
    public function __construct(private readonly SeriationRules $rules) {}

    /**
     * @throws ValidationException sobre `kind` si la vuelve seriación y cierra un ciclo
     */
    public function handle(SubjectPrerequisite $prerequisite, PrerequisiteKind $kind): SubjectPrerequisite
    {
        return DB::transaction(function () use ($prerequisite, $kind): SubjectPrerequisite {
            $plan = $this->rules->lockPlan($prerequisite->study_plan_id);

            StudyPlanSubject::query()
                ->where('study_plan_id', $plan->getKey())
                ->whereIn('subject_id', [$prerequisite->subject_id, $prerequisite->prerequisite_subject_id])
                ->sharedLock()
                ->get(['id']);

            $prerequisite->setRawAttributes($prerequisite->newQuery()->lockForUpdate()->findOrFail($prerequisite->getKey())->getAttributes(), sync: true);
            $subject = Subject::withTrashed()->findOrFail($prerequisite->subject_id);
            $required = Subject::withTrashed()->findOrFail($prerequisite->prerequisite_subject_id);
            $prerequisite->setRelation('subject', $subject)->setRelation('prerequisite', $required);
            $old = $prerequisite->kind;

            if ($old === $kind) {
                return $prerequisite;
            }

            if ($kind === PrerequisiteKind::Seriacion) {
                $this->rules->ensureNoCycle($plan, $subject, $required);
            }

            $prerequisite->kind = $kind;
            $prerequisite->save();

            activity()
                ->performedOn($prerequisite)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => ['kind' => $kind->value],
                    'old' => ['kind' => $old->value],
                ])
                ->log('Cambió a '.mb_strtolower($kind->label())." el requisito {$required->code} de {$subject->code} en el plan {$plan->code}");

            CurriculumChanged::dispatch(studyPlanId: $plan->getKey());

            return $prerequisite;
        });
    }
}
