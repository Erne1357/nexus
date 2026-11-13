<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\SetGroupTeacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
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
    public function __construct(private readonly SetGroupTeacher $setGroupTeacher) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, Teacher $teacher, User $actor, ?string $reason = null): GroupTeacherAssignment
    {
        $reason = filled($reason) ? trim($reason) : null;

        return DB::transaction(function () use ($group, $teacher, $actor, $reason): GroupTeacherAssignment {
            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

            $this->ensureLoadAllowsChanges($group);

            $current = GroupTeacherAssignment::query()->current()->where('group_id', $group->id)->lockForUpdate()->first();

            if ($current !== null && $group->teacher_id === $teacher->id && $current->teacher_id === $teacher->id) {
                return $current;
            }

            if (! $teacher->is_active || $teacher->trashed()) {
                throw ValidationException::withMessages(['teacher' => 'El docente no está activo.']);
            }

            [$position, $size] = $this->rankingPosition($group, $teacher);

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

            $current?->update(['replaced_at' => now(), 'replaced_by' => $actor->getKey()]);

            $this->setGroupTeacher->handle($group, $teacher, $actor);

            $assignment = GroupTeacherAssignment::query()->create([
                'term_id' => $group->term_id,
                'campus_id' => $group->campus_id,
                'modality_id' => $group->modality_id,
                'group_id' => $group->id,
                'teacher_id' => $teacher->id,
                'assigned_by' => $actor->getKey(),
                'assigned_at' => now(),
                'ranking_position_at_assignment' => $position,
                'ranking_size_at_assignment' => $size,
                'reason' => $reason,
            ]);

            $place = $position === null ? 'fuera del ranking' : "lugar {$position} de {$size}";

            activity()
                ->performedOn($group)
                ->causedBy($actor)
                ->event('teacher_assigned')
                ->withProperties([
                    'teacher_id' => $teacher->id,
                    'previous_teacher_id' => $current?->teacher_id,
                    'assignment_id' => $assignment->id,
                    'position' => $place,
                    'reason' => $reason,
                ])
                ->log("Asignó a {$teacher->name} al grupo {$group->code} ({$place})");

            return $assignment;
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureLoadAllowsChanges(Group $group): void
    {
        $load = AcademicLoad::query()
            ->ofDepartment($group->term_id, $group->modality_id, $group->department_id)
            ->first();

        if ($load === null || ! in_array($load->status, [LoadStatus::Staffing, LoadStatus::Adjusting], true)) {
            $label = $load?->status->label() ?? 'sin carga';

            throw ValidationException::withMessages(['group' => "La carga está en {$label}: los docentes se asignan en Asignación o Ajustes."]);
        }
    }

    /**
     * @return array{0: ?int, 1: ?int} lugar del docente en el ranking del grupo (null si no está) y tamaño del ranking
     *                                 (null si el grupo todavía no tiene ranking)
     */
    private function rankingPosition(Group $group, Teacher $teacher): array
    {
        $rankings = StaffingRanking::query()->where('group_id', $group->id);
        $size = $rankings->count();

        return [
            (clone $rankings)->where('teacher_id', $teacher->id)->value('rank'),
            $size > 0 ? $size : null,
        ];
    }
}
