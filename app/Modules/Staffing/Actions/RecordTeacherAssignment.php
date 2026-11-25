<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\SetGroupTeacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Validation\ValidationException;

/**
 * La única escritura de una asignación docente de Staffing (ADR-0020; diseño 1.33, 6.14): cierra la asignación vigente
 * del grupo (`replaced_at`), cambia `groups.teacher_id` con SetGroupTeacher, crea la fila de `group_teacher_assignments`
 * con el lugar en el ranking y el motivo, y deja la bitácora `teacher_assigned`. No valida reglas ni sabe de puestos:
 * la llaman AssignTeacher (el jefe o el JPD, con HC-16), PickSelectionGroup (el docente en su turno) y RecordPriorChoice
 * (SEL-16: carga de datos de la relación de materias, el docente ya traía el grupo escrito y no hay ranking que
 * respetar, así que no exige HC-16), cada una con sus reglas. Va dentro de la transacción de quien la llama, con la carga y el grupo ya bloqueados (LoadEditability).
 */
final class RecordTeacherAssignment
{
    public function __construct(private readonly SetGroupTeacher $setGroupTeacher) {}

    /**
     * @param  ?int  $position  lugar del docente en el ranking del grupo (null si no está)
     * @param  ?int  $size  tamaño del ranking (null si el grupo no tiene ranking)
     *
     * @throws ValidationException
     */
    public function handle(Group $group, Teacher $teacher, User $actor, ?string $reason, ?int $position, ?int $size): GroupTeacherAssignment
    {
        $current = GroupTeacherAssignment::query()->current()->where('group_id', $group->id)->lockForUpdate()->first();

        $current?->update(['replaced_at' => now(), 'replaced_by' => $actor->getKey()]);

        $this->setGroupTeacher->handle($group, $teacher, $actor);

        $assignment = GroupTeacherAssignment::query()->create([
            'term_id' => $group->term_id,
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
    }

    /**
     * El lugar del docente en el ranking del grupo (null si no está) y el tamaño del ranking (null si el grupo todavía
     * no tiene ranking).
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function rankingPosition(Group $group, Teacher $teacher): array
    {
        $rankings = StaffingRanking::query()->where('group_id', $group->id);
        $size = $rankings->count();

        return [
            (clone $rankings)->where('teacher_id', $teacher->id)->value('rank'),
            $size > 0 ? $size : null,
        ];
    }
}
