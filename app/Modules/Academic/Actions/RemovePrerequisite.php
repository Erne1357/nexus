<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Quita un requisito de una materia en su plan (borrado lógico de la seriación): la materia deja de pedirlo. Las
 * dos materias siguen en la retícula. Quitarlo no cierra ningún ciclo, así que no bloquea el plan. Quién puede lo
 * decide StudyPlanPolicy (`editGrid`) antes de llamarla.
 *
 * Vuelve a leer la seriación con bloqueo (`lockForUpdate`): una que otra sesión quitó, sola o con su materia
 * (RemovePlacement), no existe. Deja bitácora sobre la seriación con el tipo que tenía y, después del commit, avisa
 * con CurriculumChanged: cambia el avance de los alumnos del plan. Deja las dos materias en las relaciones
 * `subject` y `prerequisite`.
 */
final class RemovePrerequisite
{
    public function handle(SubjectPrerequisite $prerequisite): void
    {
        DB::transaction(function () use ($prerequisite): void {
            $prerequisite->setRawAttributes($prerequisite->newQuery()->lockForUpdate()->findOrFail($prerequisite->getKey())->getAttributes(), sync: true);
            $prerequisite->delete();

            $subject = Subject::withTrashed()->findOrFail($prerequisite->subject_id);
            $required = Subject::withTrashed()->findOrFail($prerequisite->prerequisite_subject_id);
            $prerequisite->setRelation('subject', $subject)->setRelation('prerequisite', $required);
            $kind = $prerequisite->kind;

            activity()
                ->performedOn($prerequisite)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => ['kind' => $kind->value]])
                ->log("Quitó a {$subject->code} el requisito {$required->code} (".mb_strtolower($kind->label()).") en el plan {$prerequisite->studyPlan()->withTrashed()->value('code')}");

            CurriculumChanged::dispatch(studyPlanId: $prerequisite->study_plan_id);
        });
    }
}
