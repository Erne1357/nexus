<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Quita una materia de la retícula de su plan (borrado lógico de la fila) con toda su seriación en ese plan: la
 * que pide (como materia) y la que la pide a ella (como requisito). Las llaves foráneas de `subject_prerequisites`
 * también aceptan una fila quitada, así que la seriación se quita aquí, en la misma transacción
 * (docs/dominio/modelo-de-datos.md). La materia sigue en el catálogo y en los otros planes. Quién puede lo decide
 * StudyPlanPolicy (`editGrid`) antes de llamarla.
 *
 * Vuelve a leer la fila con bloqueo (`lockForUpdate`): una que otra sesión quitó no existe, y quien agrega una
 * seriación a la materia la lee para compartir y espera. Quita la seriación sin quitar, luego la fila; deja
 * bitácora sobre la fila con el lugar que tenía y cuántas seriaciones se fueron y, después del commit, avisa con
 * CurriculumChanged. Devuelve cuántas seriaciones quitó y deja la materia en la relación `subject` de la fila.
 */
final class RemovePlacement
{
    public function handle(StudyPlanSubject $placement): int
    {
        return DB::transaction(function () use ($placement): int {
            $placement->setRawAttributes($placement->newQuery()->lockForUpdate()->findOrFail($placement->getKey())->getAttributes(), sync: true);

            $removed = SubjectPrerequisite::query()
                ->where('study_plan_id', $placement->study_plan_id)
                ->where(fn (Builder $query) => $query
                    ->where('subject_id', $placement->subject_id)
                    ->orWhere('prerequisite_subject_id', $placement->subject_id))
                ->delete();

            $placement->delete();

            $subject = $placement->subject()->withTrashed()->firstOrFail();
            $placement->setRelation('subject', $subject);
            $seriation = match ($removed) {
                0 => 'sin seriación',
                1 => 'con 1 seriación',
                default => "con sus {$removed} seriaciones",
            };

            activity()
                ->performedOn($placement)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $placement->only(PlaceSubject::FIELDS)])
                ->withProperties(['prerequisites_removed' => $removed])
                ->log("Quitó la materia {$subject->code} de la retícula del plan {$placement->studyPlan()->withTrashed()->value('code')} {$seriation}");

            CurriculumChanged::dispatch(studyPlanId: $placement->study_plan_id);

            return $removed;
        });
    }
}
