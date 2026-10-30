<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Rules\PlacementRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone una materia del catálogo en la retícula de un plan: en un semestre, con especialidad opcional del mismo
 * plan (null es el tronco común) y obligatoria u optativa. Hace cumplir PlacementRules: el semestre dentro de los
 * del plan, una materia sin borrar y activa que todavía no está en la retícula, y una especialidad del plan, sin
 * quitar y activa. Quién puede lo decide StudyPlanPolicy (`editGrid`) antes de llamarla.
 *
 * Una vez por plan y materia, también contra una fila quitada: si la materia se quitó antes, restaura su fila con
 * el lugar nuevo (su seriación vieja no vuelve: se quitó con ella). En una transacción lee el plan, la materia y
 * la especialidad para compartir y la fila con bloqueo; si otra sesión pone la misma materia al mismo tiempo, la
 * restricción única responde con el mismo error. Deja bitácora sobre la fila (alta o restauración, con el lugar)
 * y, después del commit, avisa con CurriculumChanged: cambia el avance de los alumnos del plan. Deja la materia en
 * la relación `subject` de la fila.
 */
final class PlaceSubject
{
    /** El lugar de la materia: lo que se escribe y se registra en la bitácora. */
    public const array FIELDS = ['semester', 'specialty_id', 'is_required'];

    public function __construct(private readonly PlacementRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(StudyPlan $plan, Subject $subject, int $semester, ?Specialty $specialty = null, bool $isRequired = true): StudyPlanSubject
    {
        return DB::transaction(function () use ($plan, $subject, $semester, $specialty, $isRequired): StudyPlanSubject {
            $plan = $this->rules->lockPlan($plan->getKey());
            $this->rules->ensureSemesterIsValid($plan, $semester);
            $subject = $this->rules->lockSubject($subject);
            $specialty = $this->rules->lockSpecialty($plan, $specialty?->getKey());

            $placement = StudyPlanSubject::withTrashed()
                ->where('study_plan_id', $plan->getKey())
                ->where('subject_id', $subject->getKey())
                ->lockForUpdate()
                ->first();

            if ($placement !== null && ! $placement->trashed()) {
                throw $this->rules->alreadyPlaced($subject, $placement->semester);
            }

            $place = ['semester' => $semester, 'specialty_id' => $specialty?->getKey(), 'is_required' => $isRequired];

            if ($placement === null) {
                try {
                    $placement = StudyPlanSubject::create(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey(), ...$place]);
                } catch (UniqueConstraintViolationException) {
                    throw $this->rules->alreadyPlaced($subject);
                }

                $this->record($placement, ActivityEvent::Created, "Puso la materia {$subject->code} en el semestre {$semester} del plan {$plan->code}", [
                    'attributes' => $placement->only(self::FIELDS),
                ]);
            } else {
                $old = $placement->only(self::FIELDS);
                $placement->fill($place);
                $placement->restore();

                $this->record($placement, ActivityEvent::Restored, "Volvió a poner la materia {$subject->code} en el semestre {$semester} del plan {$plan->code}", [
                    'attributes' => $placement->only(self::FIELDS),
                    'old' => $old,
                ]);
            }

            CurriculumChanged::dispatch(studyPlanId: $plan->getKey());

            return $placement->setRelation('subject', $subject);
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $changes
     */
    private function record(StudyPlanSubject $placement, ActivityEvent $event, string $description, array $changes): void
    {
        activity()
            ->performedOn($placement)
            ->event($event)
            ->withChanges($changes)
            ->log($description);
    }
}
