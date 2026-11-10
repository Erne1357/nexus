<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Enums\WaitingPrerequisiteOutcome;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Resuelve un lugar pendiente de la migración del SII (D18 y D19): le asigna semestre y lo pone en la retícula del
 * plan, con su especialidad, reutilizando PlaceSubject (semestre de 1 a los del plan, materia activa, especialidad
 * del plan; avisa con CurriculumChanged). Marca `resolved_at` y `resolved_by` y deja bitácora sobre el pendiente, con
 * el semestre, cuándo y quién (`attributes`) y el estado anterior, sin resolver (`old`), todo en una transacción. Quién puede lo decide StudyPlanPolicy (`validatePlacements`) antes de llamarla.
 *
 * Lo primero que bloquea es el plan, para escribir (`FOR UPDATE`), en el mismo orden que AddPrerequisite y
 * ApplyWaitingPrerequisites: dos validaciones del mismo plan, o una validación y una seriación capturada en el editor, se
 * esperan en lugar de interbloquearse al pasar los requisitos que esperan. Luego, el pendiente con bloqueo.
 *
 * - Un pendiente ya resuelto no se vuelve a resolver.
 * - R23: un plan borrado es un error sobre `pending`, sin tocar nada.
 * - D22 (confirmado el 5 de noviembre de 2026): uno de un plan inactivo se resuelve igual, porque la retícula de un
 *   plan viejo sigue sirviendo para el kárdex de sus egresados. PlaceSubject no pide plan activo, así que esto no
 *   abre nada en el editor de la retícula.
 * - R23 (supuesto por confirmar): si la materia ya está en la retícula del plan (alguien la puso después de la
 *   migración), el pendiente queda resuelto sin crear otro lugar ni moverla, y la bitácora dice en qué semestre estaba;
 *   devuelve ese lugar sin cambios (ni `wasRecentlyCreated` ni `wasChanged()`). Una materia que se quitó de la
 *   retícula vuelve con el semestre del pendiente, como en PlaceSubject.
 * - Al final, en la misma transacción, pasa a la seriación los requisitos del SII que esperaban y ya tienen sus dos
 *   materias en la retícula (ApplyWaitingPrerequisites; decisión del usuario del 10 de noviembre de 2026, confirmada).
 *   PlaceSubject ya avisa con CurriculumChanged; si la materia ya estaba en la retícula y entró alguno, avisa aquí.
 *
 * Devuelve el lugar y qué pasó con cada requisito que esperaba (ResolvedPlacement), para que la pantalla lo diga.
 */
final class ResolvePendingPlacement
{
    public function __construct(private readonly PlaceSubject $place, private readonly ApplyWaitingPrerequisites $prerequisites) {}

    /**
     * @throws ValidationException sobre `semester`, `subject_id`, `specialty_id` o `pending`
     */
    public function handle(StudyPlanSubjectPending $pending, int $semester, User $user): ResolvedPlacement
    {
        return DB::transaction(function () use ($pending, $semester, $user): ResolvedPlacement {
            $plan = $this->plan($pending->study_plan_id);
            $pending = StudyPlanSubjectPending::query()->with(['subject' => fn ($query) => $query->withTrashed(), 'specialty'])->lockForUpdate()->findOrFail($pending->getKey());

            if ($pending->resolved_at !== null) {
                throw ValidationException::withMessages(['pending' => 'Este pendiente ya se resolvió.']);
            }

            $placed = StudyPlanSubject::query()
                ->where('study_plan_id', $plan->getKey())
                ->where('subject_id', $pending->subject_id)
                ->lockForUpdate()
                ->first();

            if ($placed !== null) {
                $this->resolve($pending, $user, ['semester' => $placed->semester], "Resolvió el pendiente de la materia {$pending->subject->code}: ya estaba en el semestre {$placed->semester} del plan {$plan->code}");

                $results = $this->prerequisites->handle($plan, $user);

                if (array_any($results, fn (WaitingPrerequisiteResult $result): bool => $result->outcome === WaitingPrerequisiteOutcome::Entered)) {
                    CurriculumChanged::dispatch(studyPlanId: $plan->getKey());
                }

                return new ResolvedPlacement($placed->setRelation('subject', $pending->subject), $results);
            }

            $placement = $this->place->handle($plan, $pending->subject, $semester, $pending->specialty);

            $this->resolve($pending, $user, ['semester' => $semester], "Resolvió el pendiente de la materia {$pending->subject->code}: semestre {$semester} del plan {$plan->code}");

            return new ResolvedPlacement($placement, $this->prerequisites->handle($plan, $user));
        });
    }

    /**
     * El plan del pendiente, bloqueado para escribir (`FOR UPDATE`), si no está borrado (R23); activo o no (D22).
     *
     * @throws ValidationException sobre `pending`
     */
    private function plan(string $planId): StudyPlan
    {
        $plan = StudyPlan::withTrashed()->lockForUpdate()->findOrFail($planId);

        if ($plan->trashed()) {
            throw ValidationException::withMessages(['pending' => "El plan {$plan->code} ya no existe."]);
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $attributes  lo que se registra además de quién lo resolvió
     */
    private function resolve(StudyPlanSubjectPending $pending, User $user, array $attributes, string $description): void
    {
        $old = ['resolved_at' => $pending->resolved_at?->toIso8601String(), 'resolved_by' => $pending->resolved_by];
        $now = now();
        $pending->forceFill(['resolved_at' => $now, 'resolved_by' => $user->getKey()])->save();

        activity()
            ->performedOn($pending)
            ->causedBy($user)
            ->event(ActivityEvent::Updated)
            ->withChanges([
                'attributes' => [...$attributes, 'resolved_at' => $now->toIso8601String(), 'resolved_by' => $user->getKey()],
                'old' => $old,
            ])
            ->log($description);
    }
}
