<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Rules\TeacherRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Adscribe a un docente a un departamento, sin campus: el docente da clase en cualquier campus o modalidad
 * (confirmado el 30 de octubre de 2026). Es el alta de un docente nuevo (CreateTeacher, la principal) y la de uno
 * que ya existe en el departamento de quien lo da de alta (S9, supuesto por confirmar), sin tocar sus datos. Quién
 * puede lo decide TeacherPolicy (`create` con el departamento) antes de llamarla.
 *
 * Una adscripción por docente y departamento, también contra una quitada: si se había quitado, la restaura
 * (modelo-de-datos.md, Faculty). Ya adscrito ahí es un error en `adscription`. Hay a lo más una principal sin
 * borrar: pedir una segunda es un error (cambiarla es a mano), y si el docente no tiene una principal viva, la nueva
 * o la restaurada lo es aunque no se pida (S9, supuesto por confirmar).
 *
 * No toca el acceso del docente: su usuario, su rol y su alcance de `docente` no dependen de sus adscripciones (los
 * sincroniza SyncTeacherAccess). En una transacción bloquea al docente (dos cambios a sus adscripciones no se pisan
 * la principal), lee el departamento para compartir, escribe y deja bitácora sobre la adscripción (alta o
 * restauración). Después del commit avisa con AdscriptionChanged.
 */
final class AddAdscription
{
    public function __construct(private readonly TeacherRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(Teacher $teacher, Department $department, bool $primary = false): TeacherDepartment
    {
        return DB::transaction(function () use ($teacher, $department, $primary): TeacherDepartment {
            $locked = Teacher::query()->lockForUpdate()->findOrFail($teacher->getKey());
            $department = $this->rules->lockDepartment($department);
            $alreadyThere = ValidationException::withMessages(['adscription' => "{$locked->name} ya está adscrito a {$department->code}."]);

            $adscription = TeacherDepartment::withTrashed()
                ->where('teacher_id', $locked->getKey())
                ->where('department_id', $department->getKey())
                ->first();

            if ($adscription !== null && ! $adscription->trashed()) {
                throw $alreadyThere;
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
                        'is_primary' => $primary,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw $alreadyThere;
                }

                $this->record($adscription, ActivityEvent::Created, "Adscribió al docente {$locked->employee_number} a {$department->code}{$mark}", [
                    'attributes' => $adscription->only(['department_id', 'is_primary']),
                ]);
            } else {
                $old = $adscription->only(['is_primary']);
                $adscription->is_primary = $primary;
                $adscription->restore();

                $this->record($adscription, ActivityEvent::Restored, "Volvió a adscribir al docente {$locked->employee_number} a {$department->code}{$mark}", [
                    'attributes' => $adscription->only(['department_id', 'is_primary']),
                    'old' => $old,
                ]);
            }

            AdscriptionChanged::dispatch($adscription);

            return $adscription->setRelation('department', $department);
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
