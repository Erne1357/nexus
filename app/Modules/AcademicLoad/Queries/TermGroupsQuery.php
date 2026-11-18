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
 *
 * La fuente se elige por periodo y modalidad, sin mirar el campus (igual que SubjectGroupHistoryQuery); el campus solo
 * filtra los renglones después de elegirla. Periodo publicado a medias (supuesto por confirmar): si algún departamento ya
 * publicó en Nexus, el periodo cuenta solo lo publicado y las materias de los departamentos sin publicar salen con 0
 * grupos, aunque el SII tenga los suyos.
 */
final class TermGroupsQuery
{
    /**
     * @return array<string, array<string, int>> materia → campus (o `none`) → grupos físicos; vacío si el periodo no tiene renglones (con un campus, también si ese campus no tiene)
     */
    public function forTerm(string $termCode, Modality $modality, ?Campus $campus = null): array
    {
        $rows = HistoricalGroup::query()
            ->where('modality_id', $modality->getKey())
            ->where('term_code', $termCode)
            ->selectRaw('subject_id, campus_id, source, count(*) filter (where parallel_of is null) as groups')
            ->groupBy('subject_id', 'campus_id', 'source')
            ->orderBy('subject_id')
            ->orderByRaw('campus_id nulls last')
            ->get();

        $source = $rows->contains(fn (HistoricalGroup $row): bool => $row->source === HistoricalGroupSource::Nexus)
            ? HistoricalGroupSource::Nexus
            : HistoricalGroupSource::Sii;
        $groups = [];

        foreach ($rows->where('source', $source) as $row) {
            if ($campus !== null && $row->campus_id !== $campus->getKey()) {
                continue;
            }

            $key = $row->campus_id ?? SubjectGroupHistoryQuery::NO_CAMPUS;
            $groups[$row->subject_id][$key] = ($groups[$row->subject_id][$key] ?? 0) + (int) $row->groups;
        }

        return $groups;
    }
}
