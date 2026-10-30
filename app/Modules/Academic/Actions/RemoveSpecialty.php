<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\SpecialtyChanged;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Rules\SpecialtyRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Quita (borrado lógico) una especialidad de su plan. Solo una sin materias en la retícula: es la corrección
 * de una captura por error, porque quitar una especialidad de verdad «ya sería otro plan» (reglas-de-negocio.md,
 * "Especialidades"; confirmado el 29 de octubre de 2026). Con materias, una ValidationException sobre `specialty` que
 * dice cuántas. Quién puede lo decide StudyPlanPolicy (`editGrid`) antes de llamarla.
 *
 * Vuelve a leer la especialidad con bloqueo (`lockForUpdate`), así las materias que cuenta son las de ese
 * momento: quien pone una materia en ella la lee para compartir y espera. Borra, deja bitácora con los datos
 * que tenía y, después del commit, avisa con SpecialtyChanged. Sin materias, no cambia el avance de nadie.
 */
final class RemoveSpecialty
{
    public function __construct(private readonly SpecialtyRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(Specialty $specialty): Specialty
    {
        return DB::transaction(function () use ($specialty): Specialty {
            $specialty->setRawAttributes($specialty->newQuery()->lockForUpdate()->findOrFail($specialty->getKey())->getAttributes(), sync: true);

            $this->rules->ensureHasNoSubjects($specialty);

            $specialty->delete();

            activity()
                ->performedOn($specialty)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $specialty->only(CreateSpecialty::FIELDS)])
                ->log("Quitó la especialidad {$specialty->code} del plan {$specialty->studyPlan()->withTrashed()->value('code')}");

            SpecialtyChanged::dispatch($specialty);

            return $specialty;
        });
    }
}
