<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Las banderas de HC-15 de cada grupo activo (no cancelado), en una consulta: la única definición en SQL de lo que el
 * cierre revisa por grupo (sesiones, salones, docente dado de baja, justificación y choque duro vigente). La regla que
 * decide con ellas es GroupClosingFlags; la leen el veto (ClosingBlockersQuery), «Cierre de la carga» (LoadClosingQuery)
 * y «Avance del cierre» (ClosingBoardQuery).
 */
final class GroupClosingFlagsQuery
{
    /**
     * Los grupos activos de la carga (los del departamento en su modalidad, de todos los campus, M1; o solo `$groupIds`
     * de ellos), por id.
     *
     * @param  list<string>|null  $groupIds
     * @return Collection<string, GroupClosingFlags>
     */
    public function ofLoad(AcademicLoad $load, ?array $groupIds = null): Collection
    {
        return $this->read($this->query()
            ->where('groups.term_id', $load->term_id)
            ->where('groups.modality_id', $load->modality_id)
            ->where('groups.department_id', $load->department_id)
            ->when($groupIds !== null, fn (Builder $query) => $query->whereIn('groups.id', $groupIds)))
            ->keyBy('id');
    }

    /**
     * Los grupos activos de todo el periodo (el tablero de todo el Tec).
     *
     * @return Collection<int, GroupClosingFlags>
     */
    public function ofTerm(string $termId): Collection
    {
        return $this->read($this->query()->where('groups.term_id', $termId));
    }

    private function query(): Builder
    {
        return Group::query()->toBase()
            ->where('groups.status', '<>', GroupStatus::Cancelled->value)
            ->select(['groups.id', 'groups.campus_id', 'groups.modality_id', 'groups.department_id', 'groups.teacher_id'])
            ->selectRaw('exists (select 1 from group_sessions s where s.group_id = groups.id and s.is_active) as has_sessions')
            ->selectRaw('(select count(*) from group_sessions s where s.group_id = groups.id and s.is_active and s.room_id is null and s.kind <> ?) as roomless_sessions', [SessionKind::Virtual->value])
            ->selectRaw('exists (select 1 from teachers t where t.id = groups.teacher_id and (not t.is_active or t.deleted_at is not null)) as inactive_teacher')
            ->selectRaw("coalesce(btrim(groups.close_justification), '') <> '' as justified")
            ->selectRaw('exists (select 1 from schedule_conflicts c where c.group_id = groups.id and c.resolved_at is null and c.severity = ?) as stored_hard_conflict', [ConflictSeverity::Hard->value]);
    }

    /**
     * @return Collection<int, GroupClosingFlags>
     */
    private function read(Builder $query): Collection
    {
        return $query->get()->map(fn (object $row): GroupClosingFlags => new GroupClosingFlags(
            id: $row->id,
            campusId: $row->campus_id,
            modalityId: $row->modality_id,
            departmentId: $row->department_id,
            teacherId: $row->teacher_id,
            hasSessions: (bool) $row->has_sessions,
            roomlessSessions: (int) $row->roomless_sessions,
            inactiveTeacher: (bool) $row->inactive_teacher,
            justified: (bool) $row->justified,
            storedHardConflict: (bool) $row->stored_hard_conflict,
        ));
    }
}
