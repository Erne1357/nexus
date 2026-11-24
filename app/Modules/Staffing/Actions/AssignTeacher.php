<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asigna el docente de un grupo y deja la asignación en el historial (HC-16; ADR-0020).
 *
 * En una transacción bloquea el grupo y exige que la carga de su departamento esté en `staffing` o `adjusting`
 * (confirmado el 7 de noviembre de 2026), el docente activo y que las reglas duras del docente pasen (HC-01, HC-08 y
 * HC-09 por ScheduleValidator::checkTeacher; el motivo no las salva, supuesto por confirmar). HC-16: si el docente no
 * ocupa el primer lugar del ranking del grupo, o no está en él, exige un motivo. Asignar al docente vigente no cambia
 * nada y devuelve su asignación (supuesto por confirmar). Si no, reemplaza la asignación vigente, cambia
 * `groups.teacher_id` con SetGroupTeacher, crea la nueva fila y deja bitácora con el lugar y el motivo. Quién puede lo
 * decide GroupTeacherAssignmentPolicy::create antes de llamarla.
 */
final class AssignTeacher
{
    public function __construct(private readonly RecordTeacherAssignment $record) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, Teacher $teacher, User $actor, ?string $reason = null): GroupTeacherAssignment
    {
        $reason = filled($reason) ? trim($reason) : null;

        $editability = app(LoadEditability::class);

        return $editability->guard(fn (): GroupTeacherAssignment => DB::transaction(function () use ($group, $teacher, $actor, $reason, $editability): GroupTeacherAssignment {
            $load = $editability->lockLoad($group);
            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

            $this->ensureLoadAllowsChanges($load);

            $current = GroupTeacherAssignment::query()->current()->where('group_id', $group->id)->lockForUpdate()->first();

            if ($current !== null && $group->teacher_id === $teacher->id && $current->teacher_id === $teacher->id) {
                return $current;
            }

            if (! $teacher->is_active || $teacher->trashed()) {
                throw ValidationException::withMessages(['teacher' => 'El docente no está activo.']);
            }

            [$position, $size] = $this->record->rankingPosition($group, $teacher);

            if ($position !== 1 && $reason === null) {
                throw ValidationException::withMessages(['reason' => $position === null
                    ? 'No está en el ranking del grupo: escribe el motivo (HC-16).'
                    : "Ocupa el lugar {$position} de {$size}: escribe por qué no eliges al primer lugar (HC-16)."]);
            }

            $validator = app(ScheduleValidator::class);
            $validator->forget();
            $result = $validator->checkTeacher($group, $teacher);

            if ($result->hasHard()) {
                throw ValidationException::withMessages(['teacher' => $result->messages()]);
            }

            return $this->record->handle($group, $teacher, $actor, $reason, $position, $size);
        }), 'group');
    }

    /**
     * @throws ValidationException
     */
    private function ensureLoadAllowsChanges(?AcademicLoad $load): void
    {
        if ($load === null || ! $load->status->acceptsTeacherAssignment()) {
            $label = $load?->status->label() ?? 'sin carga';

            throw ValidationException::withMessages(['group' => "La carga está en {$label}: los docentes se asignan en Asignación, Ajustes o Re-análisis."]);
        }
    }
}
