<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\HistoricalGroupSource;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\Core\Models\Modality;

/**
 * El historial de grupos por materia y campus de los últimos periodos del mismo tipo (tarea 5.6): Ene-Jun (`AAAA1`) se
 * compara con los Ene-Jun anteriores y Ago-Dic (`AAAA3`) con los Ago-Dic; el verano (`AAAA2`) no entra. Dos consultas
 * en total, sin importar cuántas materias se pidan.
 *
 * Si un periodo tiene filas `nexus` (lo que Nexus publicó), esas ganan a las `sii` en todo ese periodo.
 *
 * `sameTypeTermsBefore` es el mismo cálculo que tendrá Demand; unificarlos después (anotado en la tarea 5.6).
 */
final class SubjectGroupHistoryQuery
{
    public const int DEFAULT_TERMS = 4;

    /** La llave de «sin campus» en el resultado. */
    public const string NO_CAMPUS = 'none';

    /**
     * @param  list<string>  $subjectIds
     * @return array<string, array<string, array<string, array{groups: int, capacity: int, enrolled: int|null, source: string}>>> materia → periodo → campus (o `none`) → totales
     */
    public function history(string $targetTermCode, Modality $modality, array $subjectIds, int $terms = self::DEFAULT_TERMS): array
    {
        $codes = self::sameTypeTermsBefore($targetTermCode, $terms);

        if ($codes === [] || $subjectIds === []) {
            return [];
        }

        $nexusTerms = HistoricalGroup::query()
            ->where('modality_id', $modality->getKey())
            ->where('source', HistoricalGroupSource::Nexus->value)
            ->whereIn('term_code', $codes)
            ->distinct()->pluck('term_code')->all();

        $rows = HistoricalGroup::query()
            ->where('modality_id', $modality->getKey())
            ->whereIn('term_code', $codes)
            ->whereIn('subject_id', $subjectIds)
            ->selectRaw('subject_id, term_code, campus_id, source, count(*) as groups, sum(capacity) as capacity, sum(enrolled) as enrolled')
            ->groupBy('subject_id', 'term_code', 'campus_id', 'source')
            ->get();

        $history = [];

        foreach ($rows as $row) {
            if (($row->source === HistoricalGroupSource::Nexus) !== in_array($row->term_code, $nexusTerms, true)) {
                continue;
            }

            $history[$row->subject_id][$row->term_code][$row->campus_id ?? self::NO_CAMPUS] = [
                'groups' => (int) $row->groups,
                'capacity' => (int) $row->capacity,
                'enrolled' => $row->enrolled === null ? null : (int) $row->enrolled,
                'source' => $row->source->value,
            ];
        }

        return $history;
    }

    /**
     * Los `$count` periodos del mismo tipo anteriores a `$termCode`, del más reciente al más antiguo:
     * `sameTypeTermsBefore('20271', 4)` da `['20261', '20251', '20241', '20231']`. Vacío si el código no es de tipo 1 o 3.
     *
     * @return list<string>
     */
    public static function sameTypeTermsBefore(string $termCode, int $count = self::DEFAULT_TERMS): array
    {
        if (preg_match('/^(\d{4})([13])$/', $termCode, $match) !== 1) {
            return [];
        }

        return array_map(fn (int $back): string => ((int) $match[1] - $back).$match[2], range(1, max(0, $count)));
    }
}
