<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Queries\TeacherBaseHoursQuery;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Rules\LoadBand;
use Illuminate\Support\Collection;

/**
 * SEL-16 (ampliada el 25 de noviembre de 2026, tarea 1.45): cuántas horas trae cada docente de elección previa en las
 * cargas de la selección contra su base (LoadBand) y si le falta confirmar. «Falta confirmar» es estar arriba de la banda
 * (base + 2) sin `out_of_band_acknowledged_at`; dentro o debajo de la banda no se pide nada (debajo, supuesto por
 * confirmar). Las horas son en vivo (SelectionGroupsQuery::hoursByTeacher); la base es la del CSV o, para quien no viene
 * en él, la de sus plazas en Nexus al inicio del periodo (SEL-05). Dos consultas como mucho.
 *
 * @phpstan-type PriorHours array{hours: int, base: int, band: string, over_by: int, needs_confirmation: bool, confirmed_at: ?\Carbon\CarbonImmutable}
 */
final class PriorChoiceHoursQuery
{
    public function __construct(
        private readonly SelectionGroupsQuery $groups,
        private readonly TeacherBaseHoursQuery $baseHours,
    ) {}

    /**
     * @param  Collection<int, SelectionEntry>|null  $entries  los renglones a medir (por omisión, los de elección previa de la selección)
     * @return array<string, PriorHours> por id del renglón
     */
    public function forSelection(Selection $selection, ?Collection $entries = null): array
    {
        $entries = ($entries ?? $selection->entries()->get())->filter(fn (SelectionEntry $entry): bool => $entry->isPriorChoice() && $entry->teacher_id !== null)->values();

        if ($entries->isEmpty()) {
            return [];
        }

        $hours = $this->groups->hoursByTeacher($selection, $entries->pluck('teacher_id')->all());
        $appended = $entries->where('source', SelectionEntrySource::Appended)->pluck('teacher_id')->all();
        $nexusBase = $appended === [] ? [] : $this->baseHours->forTerm(Term::query()->whereKey($selection->term_id)->firstOrFail(), $appended);
        $result = [];

        foreach ($entries as $entry) {
            $taken = (int) ($hours[$entry->teacher_id] ?? 0);
            $base = $entry->source === SelectionEntrySource::Appended ? (int) ($nexusBase[$entry->teacher_id] ?? 0) : $entry->base_hours;
            $band = LoadBand::classify($taken, $base);

            $result[$entry->getKey()] = [
                'hours' => $taken,
                'base' => $base,
                'band' => $band,
                'over_by' => max(0, $taken - ($base + LoadBand::MARGIN)),
                'needs_confirmation' => $band === 'over' && $entry->out_of_band_acknowledged_at === null,
                'confirmed_at' => $entry->out_of_band_acknowledged_at,
            ];
        }

        return $result;
    }
}
