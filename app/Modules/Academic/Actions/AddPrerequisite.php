<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Academic\Rules\SeriationRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Hace que una materia de la retícula de un plan pida otra de la misma retícula, como seriación o correquisito.
 * Hace cumplir SeriationRules: las dos en la retícula, no la misma y, si es seriación, que no cierre un ciclo. Quién
 * puede lo decide StudyPlanPolicy (`editGrid`) antes de llamarla.
 *
 * Una vez por plan, materia y requisito, también contra una fila quitada: si ya la pide es error; si se quitó
 * antes, restaura su fila con el tipo nuevo. En una transacción bloquea el plan para escribir (dos seriaciones al
 * mismo tiempo no cierran un ciclo entre las dos), las filas de la retícula de las dos materias para compartir
 * (RemovePlacement espera) y la seriación con bloqueo; si otra sesión agrega la misma al mismo tiempo, la
 * restricción única responde con el mismo error. Deja bitácora sobre la seriación (alta o restauración, con su
 * tipo) y, después del commit, avisa con CurriculumChanged: cambia el avance de los alumnos del plan. Deja las dos
 * materias en las relaciones `subject` y `prerequisite`.
 */
final class AddPrerequisite
{
    public function __construct(private readonly SeriationRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(StudyPlan $plan, Subject $subject, Subject $prerequisite, PrerequisiteKind $kind): SubjectPrerequisite
    {
        $this->rules->ensureNotItself($subject, $prerequisite);

        return DB::transaction(function () use ($plan, $subject, $prerequisite, $kind): SubjectPrerequisite {
            $plan = $this->rules->lockPlan($plan->getKey());
            $this->rules->lockPlacements($plan, $subject, $prerequisite);

            $row = SubjectPrerequisite::withTrashed()
                ->where('study_plan_id', $plan->getKey())
                ->where('subject_id', $subject->getKey())
                ->where('prerequisite_subject_id', $prerequisite->getKey())
                ->lockForUpdate()
                ->first();

            if ($row !== null && ! $row->trashed()) {
                throw $this->alreadyRequired($subject, $prerequisite, $row->kind);
            }

            if ($kind === PrerequisiteKind::Seriacion) {
                $this->rules->ensureNoCycle($plan, $subject, $prerequisite);
            }

            $description = "{$subject->code} el requisito {$prerequisite->code} ({$this->label($kind)}) en el plan {$plan->code}";

            if ($row === null) {
                try {
                    $row = SubjectPrerequisite::create([
                        'study_plan_id' => $plan->getKey(),
                        'subject_id' => $subject->getKey(),
                        'prerequisite_subject_id' => $prerequisite->getKey(),
                        'kind' => $kind,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw $this->alreadyRequired($subject, $prerequisite);
                }

                $this->record($row, ActivityEvent::Created, "Agregó a {$description}", ['attributes' => [
                    'subject_id' => $row->subject_id, 'prerequisite_subject_id' => $row->prerequisite_subject_id, 'kind' => $kind->value,
                ]]);
            } else {
                $old = $row->kind;
                $row->kind = $kind;
                $row->restore();

                $this->record($row, ActivityEvent::Restored, "Volvió a agregar a {$description}", [
                    'attributes' => ['kind' => $kind->value],
                    'old' => ['kind' => $old->value],
                ]);
            }

            CurriculumChanged::dispatch(studyPlanId: $plan->getKey());

            return $row->setRelation('subject', $subject)->setRelation('prerequisite', $prerequisite);
        });
    }

    /**
     * El error de un requisito que la materia ya pide; sin tipo cuando otra sesión lo agregó al mismo tiempo y la
     * restricción única ganó la carrera.
     */
    private function alreadyRequired(Subject $subject, Subject $prerequisite, ?PrerequisiteKind $kind = null): ValidationException
    {
        return ValidationException::withMessages([
            'prerequisite_subject_id' => "{$subject->code} ya pide {$prerequisite->code}".($kind === null ? '.' : " como {$this->label($kind)}."),
        ]);
    }

    private function label(PrerequisiteKind $kind): string
    {
        return mb_strtolower($kind->label());
    }

    /**
     * @param  array<string, array<string, mixed>>  $changes
     */
    private function record(SubjectPrerequisite $row, ActivityEvent $event, string $description, array $changes): void
    {
        activity()
            ->performedOn($row)
            ->event($event)
            ->withChanges($changes)
            ->log($description);
    }
}
