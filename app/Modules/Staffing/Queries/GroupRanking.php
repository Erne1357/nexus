<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Staffing\Models\GroupTeacherAssignment;

/**
 * El ranking completo de un grupo y su historial de asignaciones (GroupRankingQuery).
 */
final readonly class GroupRanking
{
    /**
     * @param  list<RankingRow>  $rows  en orden de lugar
     * @param  list<array{assignment: GroupTeacherAssignment, status: 'vigente'|'reemplazada'|'quitada'}>  $history  del más reciente al más antiguo
     */
    public function __construct(
        public array $rows,
        public array $history,
    ) {}
}
