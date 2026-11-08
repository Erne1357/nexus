<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\SetGroupTeacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quita el docente de un grupo (supuesto por confirmar: con motivo, y queda en el historial). Misma regla de estado de
 * la carga que AssignTeacher: `staffing` o `adjusting`. Marca la asignación vigente como reemplazada, llama a
 * SetGroupTeacher con null y deja bitácora con el motivo. Quién puede lo decide GroupTeacherAssignmentPolicy::create.
 */
final class UnassignTeacher
{
    public function __construct(private readonly SetGroupTeacher $setGroupTeacher) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, User $actor, string $reason): void
    {
        $reason = trim($reason);

        DB::transaction(function () use ($group, $actor, $reason): void {
            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

            $load = AcademicLoad::query()
                ->where('term_id', $group->term_id)
                ->where('campus_id', $group->campus_id)
                ->where('department_id', $group->department_id)
                ->first();

            if ($load === null || ! in_array($load->status, [LoadStatus::Staffing, LoadStatus::Adjusting], true)) {
                $label = $load?->status->label() ?? 'sin carga';

                throw ValidationException::withMessages(['group' => "La carga está en {$label}: los docentes se asignan en Asignación o Ajustes."]);
            }

            if ($group->teacher_id === null) {
                throw ValidationException::withMessages(['group' => 'El grupo no tiene docente.']);
            }

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Escribe el motivo para quitar al docente.']);
            }

            $previous = $group->teacher_id;

            GroupTeacherAssignment::query()->current()->where('group_id', $group->id)
                ->update(['replaced_at' => now(), 'replaced_by' => $actor->getKey()]);

            $this->setGroupTeacher->handle($group, null, $actor);

            activity()
                ->performedOn($group)
                ->causedBy($actor)
                ->event('teacher_unassigned')
                ->withProperties(['previous_teacher_id' => $previous, 'reason' => $reason])
                ->log("Quitó el docente del grupo {$group->code}");
        });
    }
}
