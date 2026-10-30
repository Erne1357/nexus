<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Rules\PlacementRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia el lugar de una materia en la retícula de su plan: solo las claves que recibe de PlaceSubject::FIELDS
 * (`semester`, `specialty_id` con null o '' para el tronco común, `is_required`); el plan y la materia no
 * cambian. Lo nuevo cumple las mismas reglas que al ponerla (PlacementRules); conservar lo que ya tiene vale
 * aunque su especialidad se haya desactivado. Quién puede lo decide StudyPlanPolicy (`editGrid`) antes de
 * llamarla.
 *
 * En una transacción lee el plan para compartir y vuelve a leer la fila con bloqueo (`lockForUpdate`), así el
 * valor anterior de la bitácora es el confirmado aunque `$placement` llegue vieja, y la deja en `$placement`; una
 * fila que otra sesión quitó no existe. Si nada cambió, no escribe, no registra ni avisa; si algo cambió, guarda,
 * deja en la bitácora el valor anterior y el nuevo y, después del commit, avisa con CurriculumChanged. Deja la
 * materia en la relación `subject` de la fila.
 */
final class UpdatePlacement
{
    public function __construct(private readonly PlacementRules $rules) {}

    /**
     * @param  array{semester?: int, specialty_id?: ?string, is_required?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(StudyPlanSubject $placement, array $data): StudyPlanSubject
    {
        $data = $this->normalize(Arr::only($data, PlaceSubject::FIELDS));

        return DB::transaction(function () use ($placement, $data): StudyPlanSubject {
            $plan = $this->rules->lockPlan($placement->study_plan_id);
            $placement->setRawAttributes($placement->newQuery()->lockForUpdate()->findOrFail($placement->getKey())->getAttributes(), sync: true);
            $old = $placement->only(PlaceSubject::FIELDS);

            if (array_key_exists('semester', $data) && $data['semester'] !== $old['semester']) {
                $this->rules->ensureSemesterIsValid($plan, $data['semester']);
            }

            if (array_key_exists('specialty_id', $data) && $data['specialty_id'] !== $old['specialty_id']) {
                $this->rules->lockSpecialty($plan, $data['specialty_id']);
            }

            $subject = $placement->subject()->withTrashed()->firstOrFail();
            $placement->setRelation('subject', $subject);
            $placement->fill($data);
            $changed = array_keys($placement->getDirty());

            if ($changed === []) {
                return $placement;
            }

            $placement->save();

            activity()
                ->performedOn($placement)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $placement->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Cambió el lugar de la materia {$subject->code} en el plan {$plan->code}");

            CurriculumChanged::dispatch(studyPlanId: $plan->getKey());

            return $placement;
        });
    }

    /**
     * El semestre como entero, la obligatoriedad como booleano y una especialidad vacía como el tronco común.
     *
     * @param  array<string, mixed>  $data
     * @return array{semester?: int, specialty_id?: ?string, is_required?: bool}
     */
    private function normalize(array $data): array
    {
        if (array_key_exists('semester', $data)) {
            $data['semester'] = (int) $data['semester'];
        }

        if (array_key_exists('specialty_id', $data)) {
            $data['specialty_id'] = $data['specialty_id'] === '' || $data['specialty_id'] === null ? null : (string) $data['specialty_id'];
        }

        if (array_key_exists('is_required', $data)) {
            $data['is_required'] = (bool) $data['is_required'];
        }

        return $data;
    }
}
