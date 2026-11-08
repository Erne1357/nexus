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

    /**
     * Quienes pueden tomar el grupo: los que no van en el bloque final.
     *
     * @return list<RankingRow>
     */
    public function eligible(): array
    {
        return array_values(array_filter($this->rows, fn (RankingRow $row): bool => ! $row->isFlagged()));
    }

    /**
     * El bloque final: sin horas, choque o no disponible (reglas-de-negocio.md, «Un solo bloque final»).
     *
     * @return list<RankingRow>
     */
    public function flagged(): array
    {
        return array_values(array_filter($this->rows, fn (RankingRow $row): bool => $row->isFlagged()));
    }

    /**
     * Cuántos del bloque final tienen cada marca, en el orden de RankingRow::MARKS y sin las que nadie tiene: «1 sin
     * horas, 2 choque, 1 no disponible». Un docente con dos marcas cuenta en las dos.
     */
    public function flaggedSummary(): string
    {
        $flagged = $this->flagged();
        $parts = [];

        foreach (RankingRow::MARKS as $mark => $label) {
            $count = count(array_filter($flagged, fn (RankingRow $row): bool => isset($row->marks()[$mark])));

            if ($count > 0) {
                $parts[] = $count.' '.mb_strtolower($label);
            }
        }

        return implode(', ', $parts);
    }
}
