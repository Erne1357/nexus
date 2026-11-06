<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Resuelve un lugar pendiente de la migración del SII (D18 y D19): le asigna semestre y lo pone en la retícula del
 * plan, con su especialidad, reutilizando PlaceSubject (semestre de 1 a los del plan, materia activa, especialidad
 * del plan; avisa con CurriculumChanged). Marca `resolved_at` y `resolved_by` y deja bitácora sobre el pendiente,
 * todo en una transacción. Un pendiente ya resuelto no se vuelve a resolver. Quién puede lo decide el componente
 * (el permiso llega con la pantalla del jefe de Servicios Escolares).
 */
final class ResolvePendingPlacement
{
    public function __construct(private readonly PlaceSubject $place) {}

    /**
     * @throws ValidationException sobre `semester`, `subject_id` o `pending`
     */
    public function handle(StudyPlanSubjectPending $pending, int $semester, User $user): StudyPlanSubject
    {
        return DB::transaction(function () use ($pending, $semester, $user): StudyPlanSubject {
            $pending = StudyPlanSubjectPending::query()->with(['studyPlan', 'subject', 'specialty'])->lockForUpdate()->findOrFail($pending->getKey());

            if ($pending->resolved_at !== null) {
                throw ValidationException::withMessages(['pending' => 'Este pendiente ya se resolvió.']);
            }

            $placement = $this->place->handle($pending->studyPlan, $pending->subject, $semester, $pending->specialty);

            $pending->forceFill(['resolved_at' => now(), 'resolved_by' => $user->getKey()])->save();

            activity()
                ->performedOn($pending)
                ->causedBy($user)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['semester' => $semester, 'resolved_by' => $user->getKey()]])
                ->log("Resolvió el pendiente de la materia {$pending->subject->code}: semestre {$semester} del plan {$pending->studyPlan->code}");

            return $placement;
        });
    }
}
