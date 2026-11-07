<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia el docente vigente de un grupo, o se lo quita (`$teacher` null). Es la única escritura de `groups.teacher_id`
 * (ADR-0020): la llama la asignación de Staffing (AssignTeacher, tarea 3.9), que decide quién puede asignar, guarda el
 * historial y exige el motivo de HC-16; esta Action no sabe de puestos.
 *
 * En una transacción relee el grupo con bloqueo. Un grupo cancelado no recibe docente. Antes de escribir pregunta al
 * validador por HC-01 (empalme del docente en cualquier campus) y lo rechaza con su mensaje; si otra sesión gana la
 * carrera, la exclusión de la base (`group_sessions_teacher_no_overlap`) lo rechaza igual. Las demás reglas del
 * docente (horas, disponibilidad) no bloquean aquí: las decide quien asigna. Si el docente no cambia, no escribe, no
 * registra ni avisa; si cambia, deja la bitácora con el anterior y el nuevo y, después del commit, avisa con
 * GroupTeacherChanged.
 */
final class SetGroupTeacher
{
    private const string OVERLAP_CONSTRAINT = 'group_sessions_teacher_no_overlap';

    public function __construct(private readonly ScheduleValidator $validator) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, ?Teacher $teacher, User $user): Group
    {
        return DB::transaction(function () use ($group, $teacher, $user): Group {
            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);
            $old = $group->teacher_id;
            $new = $teacher?->id;

            if ($old === $new) {
                return $group;
            }

            if ($new !== null && $group->status === GroupStatus::Cancelled) {
                throw ValidationException::withMessages(['teacher' => 'El grupo está cancelado: no se le asigna docente.']);
            }

            if ($teacher !== null) {
                $this->ensureNoOverlap($group, $teacher);
            }

            try {
                $group->update(['teacher_id' => $new]);
            } catch (QueryException $exception) {
                if (! str_contains($exception->getMessage(), self::OVERLAP_CONSTRAINT)) {
                    throw $exception;
                }

                throw ValidationException::withMessages(['teacher' => "HC-01: {$teacher?->name} ya da clase a la hora de una sesión de este grupo."]);
            }

            $this->validator->forget();

            activity()
                ->causedBy($user)
                ->performedOn($group)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['teacher_id' => $new], 'old' => ['teacher_id' => $old]])
                ->log($new === null ? "Quitó el docente del grupo {$group->code}" : "Asignó docente al grupo {$group->code}");

            GroupTeacherChanged::dispatch($group, $old, $new);

            return $group;
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureNoOverlap(Group $group, Teacher $teacher): void
    {
        $overlaps = $this->validator->checkTeacher($group, $teacher)->forRule('HC-01');

        if ($overlaps !== []) {
            throw ValidationException::withMessages(['teacher' => array_map(fn (Violation $violation): string => $violation->message, $overlaps)]);
        }
    }
}
