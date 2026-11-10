<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;

/**
 * Lo que le falta a la carga de un departamento en un campus para cerrarse o publicarse (HC-15; confirmado el 10 de
 * noviembre de 2026). Es la cuenta del veto (BlockClosingWithPendingGroups) y de la pantalla de cierre, que deshabilita
 * el botón con estos mismos motivos; el veto sigue decidiendo.
 *
 * Por cada grupo activo del departamento en el campus y el periodo:
 *
 * - **choque duro**: lo calcula el validador en ese momento, con la foto de todo el campus (HC-01 cruza departamentos y
 *   campus), no `schedule_conflicts`, que el job pudo no recalcular todavía. Olvida la foto antes, porque el validador
 *   es `scoped` y en el mismo request pudo haber leído una vieja (por ejemplo, justo después de asignar el último salón);
 * - **sin horario**: sin sesiones activas, si el campus usa bloques de tiempo;
 * - **sin docente** sin justificación;
 * - **sin salón**: alguna sesión activa presencial (no virtual) sin salón, si el campus usa salones, sin justificación;
 * - **docente dado de baja**: inactivo o borrado; no se justifica (P10).
 */
final class ClosingBlockersQuery
{
    public function __construct(private readonly ScheduleValidator $validator) {}

    public function of(AcademicLoad $load): ClosingBlockers
    {
        $profile = $load->campus->profile;
        $rows = Group::query()
            ->where('term_id', $load->term_id)
            ->where('campus_id', $load->campus_id)
            ->where('department_id', $load->department_id)
            ->where('status', '<>', GroupStatus::Cancelled->value)
            ->select(['groups.id', 'groups.teacher_id', 'groups.close_justification'])
            ->selectRaw('exists (select 1 from group_sessions s where s.group_id = groups.id and s.is_active) as has_sessions')
            ->selectRaw('exists (select 1 from group_sessions s where s.group_id = groups.id and s.is_active and s.room_id is null and s.kind <> ?) as missing_room', [SessionKind::Virtual->value])
            ->selectRaw('exists (select 1 from teachers t where t.id = groups.teacher_id and (not t.is_active or t.deleted_at is not null)) as inactive_teacher')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return new ClosingBlockers;
        }

        $justified = fn (object $row): bool => $row->close_justification !== null && trim($row->close_justification) !== '';
        $withoutTeacher = fn (object $row): bool => $row->teacher_id === null;
        $withoutRoom = fn (object $row): bool => $profile->usesRooms && (bool) $row->missing_room;

        return new ClosingBlockers(
            conflicted: $this->conflicted($load, $rows->pluck('id')->all()),
            unscheduled: $profile->usesTimeBlocks ? $rows->reject(fn (object $row): bool => (bool) $row->has_sessions)->count() : 0,
            withoutTeacher: $rows->filter(fn (object $row): bool => $withoutTeacher($row) && ! $justified($row))->count(),
            withoutRoom: $rows->filter(fn (object $row): bool => $withoutRoom($row) && ! $justified($row))->count(),
            inactiveTeacher: $rows->filter(fn (object $row): bool => (bool) $row->inactive_teacher)->count(),
            justified: $rows->filter(fn (object $row): bool => $justified($row) && ($withoutTeacher($row) || $withoutRoom($row)))->count(),
        );
    }

    /**
     * Los grupos del departamento con al menos un choque duro.
     *
     * @param  list<string>  $groupIds
     */
    private function conflicted(AcademicLoad $load, array $groupIds): int
    {
        $department = Department::query()->withTrashed()->whereKey($load->department_id)->firstOrFail();

        $this->validator->forget();
        $hard = array_filter(
            $this->validator->validateLoad($load->term, $load->campus, $department)->violations(),
            fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard,
        );

        return count(array_intersect(array_unique(array_filter(array_map(fn (Violation $violation): ?string => $violation->groupId, $hard))), $groupIds));
    }
}
