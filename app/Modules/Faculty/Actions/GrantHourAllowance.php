<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Events\HourAllowanceChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherHourAllowance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Autoriza horas a un docente en un periodo (tarea 1.33, SEL-12): el límite de HC-08 pasa a ser el mayor entre su base
 * más la tolerancia y estas horas. Una fila por docente y periodo: si ya tiene, la reemplaza. La llama Staffing cuando
 * el docente termina su carga en la orden de selección (FinishMyLoad, SEL-05), con su total de HC-08; quién puede lo
 * decide quien la llama.
 *
 * En una transacción bloquea al docente, escribe, deja bitácora y, después del commit, avisa con HourAllowanceChanged.
 */
final class GrantHourAllowance
{
    /**
     * @throws ValidationException
     */
    public function handle(Teacher $teacher, Term $term, int $hours, string $reason, User $actor): TeacherHourAllowance
    {
        if ($hours < 1) {
            throw ValidationException::withMessages(['hours' => 'Las horas autorizadas deben ser al menos 1.']);
        }

        return DB::transaction(function () use ($teacher, $term, $hours, $reason, $actor): TeacherHourAllowance {
            $locked = Teacher::query()->lockForUpdate()->findOrFail($teacher->getKey());

            $allowance = TeacherHourAllowance::query()->firstOrNew(['teacher_id' => $locked->getKey(), 'term_id' => $term->getKey()]);
            $previous = $allowance->exists ? $allowance->hours : null;

            $allowance->fill(['hours' => $hours, 'reason' => $reason, 'granted_by' => $actor->getKey(), 'granted_at' => now()])->save();

            activity()
                ->performedOn($allowance)
                ->causedBy($actor)
                ->event('hour_allowance_granted')
                ->withProperties(['teacher_id' => $locked->getKey(), 'term_id' => $term->getKey(), 'hours' => $hours, 'previous_hours' => $previous, 'reason' => $reason])
                ->log("Autorizó {$hours} h a {$locked->name} en {$term->name}");

            HourAllowanceChanged::dispatch($allowance);

            return $allowance;
        });
    }
}
