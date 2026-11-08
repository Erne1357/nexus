<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Rules\PositionTag;

/**
 * El ranking completo y el historial de asignaciones de los grupos que la asignación docente tiene abiertos, y el
 * lugar de un docente en el ranking de un grupo (HC-16). Un número fijo de consultas sin importar cuántos grupos o
 * renglones: los rankings con sus docentes, las plazas vigentes de esos docentes y el historial con quién asignó y
 * quién reemplazó.
 */
final class GroupRankingQuery
{
    /**
     * @param  list<string>  $groupIds
     * @return array<string, GroupRanking> por id de grupo, en el orden pedido
     */
    public function forGroups(Term $term, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $rankings = StaffingRanking::query()
            ->whereIn('group_id', $groupIds)
            ->with(['teacher' => fn ($query) => $query->withTrashed()->select(['id', 'name'])])
            ->orderBy('rank')
            ->get();
        $positions = $this->positionsOf($term, $rankings->pluck('teacher_id')->unique()->values()->all());
        $rows = $rankings->groupBy('group_id');

        $history = GroupTeacherAssignment::query()
            ->whereIn('group_id', $groupIds)
            ->with([
                'teacher' => fn ($query) => $query->withTrashed()->select(['id', 'name']),
                'assignedBy:id,name',
                'replacedBy:id,name',
            ])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('group_id');

        $result = [];

        foreach ($groupIds as $groupId) {
            $result[$groupId] = new GroupRanking(
                rows: $rows->get($groupId, collect())->map(fn (StaffingRanking $ranking): RankingRow => self::row($ranking, $positions))->values()->all(),
                history: $history->get($groupId, collect())->values()->map(fn (GroupTeacherAssignment $assignment, int $index): array => [
                    'assignment' => $assignment,
                    'status' => match (true) {
                        $assignment->replaced_at === null => 'vigente',
                        $index > 0 => 'reemplazada',
                        default => 'quitada',
                    },
                ])->all(),
            );
        }

        return $result;
    }

    /**
     * El lugar del docente en el ranking del grupo (null si no está) y de cuántos es el ranking, como lo guarda
     * AssignTeacher.
     *
     * @return array{0: ?int, 1: int}
     */
    public function place(Group $group, string $teacherId): array
    {
        $rankings = StaffingRanking::query()->where('group_id', $group->getKey());
        $rank = (clone $rankings)->where('teacher_id', $teacherId)->value('rank');

        return [$rank === null ? null : (int) $rank, $rankings->count()];
    }

    /**
     * La etiqueta de las plazas vigentes el primer día del periodo (PositionTag), por id de docente; una consulta.
     *
     * @param  list<string>  $teacherIds
     * @return array<string, string>
     */
    public function positionsOf(Term $term, array $teacherIds): array
    {
        if ($teacherIds === []) {
            return [];
        }

        return TeacherPosition::query()
            ->currentOn($term->starts_on)
            ->whereIn('teacher_id', $teacherIds)
            ->get(['teacher_id', 'kind', 'base_hours'])
            ->groupBy('teacher_id')
            ->map(fn ($positions): string => PositionTag::of($positions->map(fn (TeacherPosition $position): array => [
                'kind' => $position->kind,
                'base_hours' => (int) $position->base_hours,
            ])))
            ->all();
    }

    /**
     * Un renglón del ranking con su desglose; el docente debe venir cargado.
     *
     * @param  array<string, string>  $positions  de positionsOf()
     */
    public static function row(StaffingRanking $ranking, array $positions): RankingRow
    {
        $breakdown = $ranking->breakdown ?? [];
        $evaluation = $breakdown['evaluation'] ?? null;
        $remaining = $breakdown['remaining_hours'] ?? null;
        $remainingAfter = $breakdown['remaining_after_hours'] ?? null;

        return new RankingRow(
            rank: $ranking->rank,
            score: (float) $ranking->score,
            teacherId: $ranking->teacher_id,
            teacherName: $ranking->teacher?->name ?? '',
            positions: $positions[$ranking->teacher_id] ?? PositionTag::of([]),
            baseHours: (int) ($breakdown['base_hours'] ?? 0),
            historyCount: (int) ($breakdown['history_count'] ?? 0),
            qualification: QualificationLevel::tryFrom((string) ($breakdown['qualification'] ?? '')),
            evaluation: is_numeric($evaluation) ? (float) $evaluation : null,
            remainingHours: is_numeric($remaining) ? (int) $remaining : null,
            remainingAfterHours: is_numeric($remaining) && is_numeric($remainingAfter) ? (int) $remainingAfter : null,
            flags: array_values(array_map('strval', $breakdown['flags'] ?? [])),
            fromOtherDepartment: (bool) ($breakdown['from_other_department'] ?? false),
        );
    }
}
