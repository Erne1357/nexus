<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de baja a un docente o lo reactiva (confirmado el 30 de octubre de 2026): la baja desactiva su usuario, salvo
 * que sea admin, y termina sus jefaturas vigentes; reactivarlo devuelve su usuario. Eso lo hace SyncTeacherAccess,
 * que esta Action llama en la misma transacción. Quién puede lo decide TeacherPolicy (`deactivate`).
 *
 * Vuelve a leer al docente con bloqueo; si ya estaba en ese estado, no escribe, no registra ni avisa. Si cambia,
 * deja en la bitácora el estado anterior y el nuevo y, después del commit, avisa con TeacherUpdated. Deja el
 * docente leído en `$teacher`.
 */
final class SetTeacherActive
{
    public function __construct(private readonly SyncTeacherAccess $syncTeacherAccess) {}

    /**
     * @throws RuntimeException si al reactivarlo hay que crearle usuario y falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(Teacher $teacher, bool $active): Teacher
    {
        return DB::transaction(function () use ($teacher, $active): Teacher {
            $teacher->setRawAttributes($teacher->newQuery()->lockForUpdate()->findOrFail($teacher->getKey())->getAttributes(), sync: true);

            if ($teacher->is_active === $active) {
                return $teacher;
            }

            $teacher->forceFill(['is_active' => $active])->save();

            activity()
                ->performedOn($teacher)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['is_active' => $active], 'old' => ['is_active' => ! $active]])
                ->log(($active ? 'Reactivó al docente ' : 'Dio de baja al docente ').$teacher->employee_number);

            $this->syncTeacherAccess->handle($teacher);

            TeacherUpdated::dispatch($teacher, ['is_active']);

            return $teacher;
        });
    }
}
