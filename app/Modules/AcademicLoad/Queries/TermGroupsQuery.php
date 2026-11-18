<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\HistoricalGroupSource;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;

/**
 * Los grupos físicos que de verdad se abrieron en un periodo, por materia y campus (tarea 5.9, DM-27): los renglones de
 * `historical_groups` sin `parallel_of` (un paralelo es el mismo grupo para otra carrera). Si el periodo tiene renglones
 * `nexus` se usan esos y no los `sii`; nunca se suman las dos fuentes. Una sola consulta agrupada.
 */
final class TermGroupsQuery
{
    /**
     * @return array<string, array<string, int>> materia → campus (o `none`) → grupos físicos; vacío si el periodo no tiene renglones
     */
    public function forTerm(string $termCode, Modality $modality, ?Campus $campus = null): array
    {
        $rows = HistoricalGroup::query()
            ->where('modality_id', $modality->getKey())
            ->where('term_code', $termCode)
            ->when($campus !== null, fn ($query) => $query->where('campus_id', $campus->getKey()))
            ->selectRaw('subject_id, campus_id, source, count(*) filter (where parallel_of is null) as groups')
            ->groupBy('subject_id', 'campus_id', 'source')
            ->get();

        $source = $rows->contains(fn (HistoricalGroup $row): bool => $row->source === HistoricalGroupSource::Nexus)
            ? HistoricalGroupSource::Nexus
            : HistoricalGroupSource::Sii;
        $groups = [];

        foreach ($rows->where('source', $source) as $row) {
            $key = $row->campus_id ?? SubjectGroupHistoryQuery::NO_CAMPUS;
            $groups[$row->subject_id][$key] = ($groups[$row->subject_id][$key] ?? 0) + (int) $row->groups;
        }

        return $groups;
    }
}
