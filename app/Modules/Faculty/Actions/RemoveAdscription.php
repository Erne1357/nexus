<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Quita una adscripción de un docente (borrado lógico; AddAdscription la restaura si se vuelve a poner). S8,
 * supuesto por confirmar: no se quita la única (para eso está la baja) ni la principal (primero se elige otra con
 * SetPrimaryAdscription). Quién puede lo decide TeacherPolicy (`removeAdscription`) antes de llamarla.
 *
 * En una transacción bloquea al docente y la adscripción, revisa S8 sobre lo confirmado, borra, deja bitácora y
 * llama a SyncTeacherAccess, que cierra el alcance de `docente` en el campus donde ya no tiene ninguna (S14).
 * Después del commit avisa con AdscriptionChanged. Una adscripción ya quitada es un error en `adscription`.
 */
final class RemoveAdscription
{
    public function __construct(private readonly SyncTeacherAccess $syncTeacherAccess) {}

    /**
     * @throws ValidationException
     * @throws RuntimeException si SyncTeacherAccess tiene que crear el usuario y falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(TeacherDepartment $adscription): void
    {
        DB::transaction(function () use ($adscription): void {
            $teacher = Teacher::query()->lockForUpdate()->findOrFail($adscription->teacher_id);
            $locked = TeacherDepartment::query()->lockForUpdate()->where('teacher_id', $teacher->getKey())->find($adscription->getKey())
                ?? throw ValidationException::withMessages(['adscription' => 'Esa adscripción ya se quitó.']);

            $department = Department::withTrashed()->find($locked->department_id);
            $campus = Campus::withTrashed()->find($locked->campus_id);
            $where = "{$department?->code} en {$campus?->code}";

            if (TeacherDepartment::query()->where('teacher_id', $teacher->getKey())->count() <= 1) {
                throw ValidationException::withMessages(['adscription' => "La adscripción {$where} es la única de {$teacher->name}: para quitarla, dalo de baja."]);
            }

            if ($locked->is_primary) {
                throw ValidationException::withMessages(['adscription' => "La adscripción {$where} es la principal de {$teacher->name}: primero elige otra como principal."]);
            }

            $locked->delete();

            activity()
                ->performedOn($locked)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $locked->only(['department_id', 'campus_id', 'is_primary'])])
                ->withProperties(['teacher_id' => $teacher->getKey()])
                ->log("Quitó la adscripción {$where} al docente {$teacher->employee_number}");

            $this->syncTeacherAccess->handle($teacher);

            AdscriptionChanged::dispatch($locked);
        });
    }
}
