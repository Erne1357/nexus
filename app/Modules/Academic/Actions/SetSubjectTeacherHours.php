<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\SubjectUpdated;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * PL-02 (tarea 1.73): marca si las horas de la materia cuentan en la carga del docente; queda en la materia para los
 * periodos siguientes. Quién puede lo decide SubjectPolicy::setTeacherHours antes de llamarla (el jefe del
 * departamento de la materia y el admin); el comando de una sola vez la llama sin usuario. Vuelve a leer la materia
 * con bloqueo; si nada cambió, no escribe ni registra. Después del commit avisa con SubjectUpdated
 * (`counts_teacher_hours`), con el que Scheduling recalcula los choques (HC-08) y Staffing la carga por docente y el
 * ranking.
 */
final class SetSubjectTeacherHours
{
    public function handle(Subject $subject, bool $counts, ?User $actor): Subject
    {
        return DB::transaction(function () use ($subject, $counts, $actor): Subject {
            $subject->setRawAttributes($subject->newQuery()->lockForUpdate()->findOrFail($subject->getKey())->getAttributes(), sync: true);
            $old = (bool) $subject->counts_teacher_hours;

            if ($old === $counts) {
                return $subject;
            }

            $subject->counts_teacher_hours = $counts;
            $subject->save();

            activity()
                ->performedOn($subject)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['counts_teacher_hours' => $counts], 'old' => ['counts_teacher_hours' => $old]])
                ->log($counts
                    ? "Marcó que las horas de la materia {$subject->code} cuentan en la carga del docente"
                    : "Marcó que las horas de la materia {$subject->code} no cuentan en la carga del docente");

            SubjectUpdated::dispatch($subject, ['counts_teacher_hours']);

            return $subject;
        });
    }
}
