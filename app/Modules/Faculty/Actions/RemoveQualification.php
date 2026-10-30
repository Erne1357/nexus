<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Quita una materia del perfil de un docente (S17, supuesto por confirmar): borrado lógico, con su nivel en la
 * bitácora. Quién puede lo decide TeacherPolicy (`manageQualification`) antes de llamarla. Una ya quitada es un
 * error en `qualification`. Después del commit avisa con QualificationChanged.
 */
final class RemoveQualification
{
    /**
     * @throws ValidationException
     */
    public function handle(TeacherSubjectQualification $qualification): void
    {
        DB::transaction(function () use ($qualification): void {
            $locked = TeacherSubjectQualification::query()->lockForUpdate()->find($qualification->getKey())
                ?? throw ValidationException::withMessages(['qualification' => 'Esa materia ya se quitó del perfil.']);

            $locked->delete();

            activity()
                ->performedOn($locked)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => ['subject_id' => $locked->subject_id, 'level' => $locked->level->value, 'notes' => $locked->notes]])
                ->withProperties(['teacher_id' => $locked->teacher_id])
                ->log('Quitó una materia del perfil del docente');

            QualificationChanged::dispatch($locked);
        });
    }
}
