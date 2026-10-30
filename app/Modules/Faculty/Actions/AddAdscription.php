<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Rules\TeacherRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Adscribe a un docente a un departamento en un campus: el alta de un docente nuevo (CreateTeacher, la principal) y
 * la de uno que ya existe en el departamento de quien lo da de alta (S9, supuesto por confirmar), sin tocar sus
 * datos. Quién puede lo decide TeacherPolicy (`create` con el departamento y el campus) antes de llamarla.
 *
 * Una adscripción por docente, departamento y campus, también contra una quitada: si se había quitado, la restaura
 * (modelo-de-datos.md, Faculty). Ya adscrito ahí es un error en `adscription`. Hay a lo más una principal sin
 * borrar: pedir una segunda es un error (cambiarla es a mano, como en S5), y la primera de un docente que no tiene
 * principal lo es aunque no se pida.
 *
 * En una transacción bloquea al docente (dos adscripciones a la vez no se pisan la principal), lee el departamento
 * y el campus para compartir, escribe, deja bitácora sobre la adscripción (alta o restauración) y llama a
 * SyncTeacherAccess, que abre el alcance de `docente` en el campus (S14). Después del commit avisa con
 * AdscriptionChanged.
 */
final class AddAdscription
{
    public function __construct(
        private readonly TeacherRules $rules,
        private readonly SyncTeacherAccess $syncTeacherAccess,
    ) {}

    /**
     * @throws ValidationException
     * @throws RuntimeException si SyncTeacherAccess tiene que crear el usuario y falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(Teacher $teacher, Department $department, Campus $campus, bool $primary = false): TeacherDepartment
    {
        return DB::transaction(function () use ($teacher, $department, $campus, $primary): TeacherDepartment {
            $locked = Teacher::query()->lockForUpdate()->findOrFail($teacher->getKey());
            $department = $this->rules->lockDepartment($department);
            $campus = $this->rules->lockCampus($campus);
            $where = "a {$department->code} en {$campus->code}";

            $adscription = TeacherDepartment::withTrashed()
                ->where('teacher_id', $locked->getKey())
                ->where('department_id', $department->getKey())
                ->where('campus_id', $campus->getKey())
                ->first();

            if ($adscription !== null && ! $adscription->trashed()) {
                throw ValidationException::withMessages(['adscription' => "{$locked->name} ya está adscrito {$where}."]);
            }

            $hasPrimary = TeacherDepartment::query()->where('teacher_id', $locked->getKey())->where('is_primary', true)->exists();

            if ($primary && $hasPrimary) {
                throw ValidationException::withMessages(['adscription' => "{$locked->name} ya tiene una adscripción principal."]);
            }

            $primary = ! $hasPrimary;
            $mark = $primary ? ' (principal)' : '';

            if ($adscription === null) {
                try {
                    $adscription = TeacherDepartment::create([
                        'teacher_id' => $locked->getKey(),
                        'department_id' => $department->getKey(),
                        'campus_id' => $campus->getKey(),
                        'is_primary' => $primary,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages(['adscription' => "{$locked->name} ya está adscrito {$where}."]);
                }

                $this->record($adscription, ActivityEvent::Created, "Adscribió al docente {$locked->employee_number} {$where}{$mark}", [
                    'attributes' => $adscription->only(['department_id', 'campus_id', 'is_primary']),
                ]);
            } else {
                $old = $adscription->only(['is_primary']);
                $adscription->is_primary = $primary;
                $adscription->restore();

                $this->record($adscription, ActivityEvent::Restored, "Volvió a adscribir al docente {$locked->employee_number} {$where}{$mark}", [
                    'attributes' => $adscription->only(['department_id', 'campus_id', 'is_primary']),
                    'old' => $old,
                ]);
            }

            $this->syncTeacherAccess->handle($teacher);

            AdscriptionChanged::dispatch($adscription);

            return $adscription->setRelations(['department' => $department, 'campus' => $campus]);
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $changes
     */
    private function record(TeacherDepartment $adscription, ActivityEvent $event, string $description, array $changes): void
    {
        activity()
            ->performedOn($adscription)
            ->event($event)
            ->withChanges($changes)
            ->withProperties(['teacher_id' => $adscription->teacher_id])
            ->log($description);
    }
}
