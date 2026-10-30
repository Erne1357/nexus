<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * «Quitar de mi departamento»: quita, con borrado lógico, una adscripción de un docente (reglas-de-negocio.md,
 * "Adscripciones"). Si era la principal, la principal pasa a la adscripción más antigua que le quede (confirmado el
 * 30 de octubre de 2026). La más antigua es la de `created_at` menor y, a igual fecha, la de id menor. Cuando exista
 * la carga de la fase 3, pasará a la del departamento donde tenga más horas asignadas (confirmado el 30 de octubre
 * de 2026). Si no le queda ninguna, el docente sigue activo y sin departamento (confirmado el 30 de octubre de 2026);
 * su usuario, su rol y su alcance de `docente` no dependen de sus adscripciones, así que no se tocan. Quitar una que
 * no es la principal no cambia la principal. La quitada deja de ser principal, así que restaurarla no choca con la
 * nueva. Quién puede lo decide la policy de docentes antes de llamarla.
 *
 * En una transacción bloquea al docente (dos cambios a sus adscripciones no se pisan la principal) y sus
 * adscripciones, borra, pasa la principal si hace falta y deja bitácora sobre la quitada (con la principal que le
 * queda al docente) y sobre la principal nueva. Después del commit avisa con AdscriptionChanged. Una adscripción ya
 * quitada es un error en `adscription`.
 */
final class RemoveAdscription
{
    /**
     * @return TeacherDepartment|null la adscripción que pasó a ser la principal, o null si la principal no cambió
     *                                de lugar (la quitada no era la principal, o era la única)
     *
     * @throws ValidationException
     */
    public function handle(TeacherDepartment $adscription): ?TeacherDepartment
    {
        return DB::transaction(function () use ($adscription): ?TeacherDepartment {
            $teacher = Teacher::query()->lockForUpdate()->findOrFail($adscription->teacher_id);
            $adscriptions = TeacherDepartment::query()
                ->where('teacher_id', $teacher->getKey())
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $removed = $adscriptions->firstWhere('id', $adscription->getKey())
                ?? throw ValidationException::withMessages(['adscription' => 'Esa adscripción ya se quitó.']);
            $remaining = $adscriptions->reject(fn (TeacherDepartment $other): bool => $other->is($removed))->values();
            $wasPrimary = $removed->is_primary;
            $promoted = $wasPrimary ? $remaining->first() : null;
            $primary = $wasPrimary ? $promoted : $remaining->firstWhere('is_primary', true);
            $codes = Department::withTrashed()->whereKey($adscriptions->pluck('department_id'))->pluck('code', 'id');
            $from = $codes[$removed->department_id];

            if ($wasPrimary) {
                $removed->forceFill(['is_primary' => false])->save();
            }

            $removed->delete();
            $promoted?->forceFill(['is_primary' => true])->save();

            $outcome = match (true) {
                $remaining->isEmpty() => '; queda sin departamento',
                $promoted !== null => "; su adscripción principal pasa a {$codes[$promoted->department_id]}",
                default => '',
            };

            activity()
                ->performedOn($removed)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => ['department_id' => $removed->department_id, 'is_primary' => $wasPrimary]])
                ->withProperties(['teacher_id' => $teacher->getKey(), 'primary_adscription_id' => $primary?->getKey()])
                ->log("Quitó al docente {$teacher->employee_number} del departamento {$from}{$outcome}");

            if ($promoted !== null) {
                activity()
                    ->performedOn($promoted)
                    ->event(ActivityEvent::Updated)
                    ->withChanges(['attributes' => ['is_primary' => true], 'old' => ['is_primary' => false]])
                    ->withProperties(['teacher_id' => $teacher->getKey()])
                    ->log("Hizo principal la adscripción del docente {$teacher->employee_number} a {$codes[$promoted->department_id]} al quitarlo de {$from}");
            }

            AdscriptionChanged::dispatch($removed);

            return $promoted;
        });
    }
}
