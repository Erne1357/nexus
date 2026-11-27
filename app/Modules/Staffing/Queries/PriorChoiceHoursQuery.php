<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Rules\LoadBand;
use Illuminate\Support\Collection;

/**
 * SEL-16 (ampliada el 25 de noviembre de 2026, tarea 1.45): cuántas horas trae cada docente de elección previa en las
 * cargas de la selección contra su base (LoadBand) y si le falta confirmar. «Falta confirmar» sale de la foto al publicar
 * (`prior_choice_confirm_required_at`, que PublishSelection pone a quien quedó arriba de la banda) sin
 * `out_of_band_acknowledged_at`: lo que cambie después no lo crea ni lo quita. Dentro o debajo de la banda al publicar no
 * se pide nada (debajo, supuesto por confirmar). Las horas son en vivo (SelectionGroupsQuery::hoursByTeacher); la base es la del CSV o, para quien no viene
 * en él, la de sus plazas en Nexus al inicio del periodo (SEL-05). Dos consultas como mucho.
 *
 * @phpstan-type PriorHours array{hours: int, base: int, band: string, over_by: int, needs_confirmation: bool, confirmed_at: ?\Carbon\CarbonImmutable}
 */
final class PriorChoiceHoursQuery
{
    public function __construct(
        private readonly SelectionGroupsQuery $groups,
        private readonly TeachingTargetQuery $targets,
    ) {}

    /**
     * @param  Collection<int, SelectionEntry>|null  $entries  los renglones a medir (por omisión, los de elección previa de la selección)
     * @param  Term|null  $term  el periodo, si quien llama ya lo tiene (solo se busca para quien no viene en el CSV)
     * @return array<string, PriorHours> por id del renglón
     */
    public function forSelection(Selection $selection, ?Collection $entries = null, ?Term $term = null): array
    {
        $entries = ($entries ?? $selection->entries()->get())->filter(fn (SelectionEntry $entry): bool => $entry->isPriorChoice() && $entry->teacher_id !== null)->values();

        if ($entries->isEmpty()) {
            return [];
        }

        $hours = $this->groups->hoursByTeacher($selection, $entries->pluck('teacher_id')->all());
        $targets = $this->targets->forTerm($this->termWithDates($selection, $term), $entries->pluck('base_hours', 'teacher_id')->map(fn ($csv): int => (int) $csv)->all());
        $result = [];

        foreach ($entries as $entry) {
            $taken = (int) ($hours[$entry->teacher_id] ?? 0);
            $base = (int) ($targets[$entry->teacher_id] ?? 0);
            $band = LoadBand::classify($taken, $base);

            $result[$entry->getKey()] = [
                'hours' => $taken,
                'base' => $base,
                'band' => $band,
                'over_by' => max(0, $taken - ($base + LoadBand::MARGIN)),
                'needs_confirmation' => $entry->prior_choice_confirm_required_at !== null && $entry->out_of_band_acknowledged_at === null,
                'confirmed_at' => $entry->out_of_band_acknowledged_at,
            ];
        }

        return $result;
    }

    /**
     * El periodo con su fecha de inicio, que es la que mide la base de Nexus (TeachingTargetQuery). El que trae quien
     * llama o la relación ya cargada solo sirven si traen `starts_on`: la pantalla «Oferta» carga el periodo sin sus
     * fechas (solo id, clave y nombre), así que en ese caso se busca completo.
     */
    private function termWithDates(Selection $selection, ?Term $term): Term
    {
        $loaded = $selection->relationLoaded('term') ? $selection->term : null;

        foreach ([$term, $loaded] as $candidate) {
            if ($candidate instanceof Term && $candidate->starts_on !== null) {
                return $candidate;
            }
        }

        return Term::query()->whereKey($selection->term_id)->firstOrFail();
    }
}
