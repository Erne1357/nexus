<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\ForecastReason;
use App\Modules\AcademicLoad\Enums\SuggestionState;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Models\Modality;
use App\Modules\Demand\Rules\DemandSettings;
use DateTimeImmutable;

/**
 * La propuesta de carrera frente a la proyección de Demand (tarea 5.6, opción C «Revisión de la proyección», elegida por el
 * usuario el 17 de noviembre de 2026; ProposalForecastQuery::review): cada fila con su estado y sus motivos
 * (ProposalForecastRow), de cuándo es la proyección (`computedAt`, la más reciente de la carrera; null si no hay) y los
 * parámetros con que se calculó. Lo que se cuenta y se revisa es de las columnas que se piden (`$keys`, llaves de
 * ProposalOverview::columnKey): las que el usuario corrige, o todas si no corrige ninguna.
 */
final readonly class ProposalForecastReview
{
    /**
     * @param  array<string, ProposalForecastRow>  $rows  por id de fila, en el orden de la propuesta (semestre, clave y columna)
     */
    public function __construct(
        public ?DateTimeImmutable $computedAt,
        public DemandSettings $settings,
        public string $termCode,
        public Modality $modality,
        public array $rows,
    ) {}

    public function hasForecast(): bool
    {
        return $this->computedAt !== null;
    }

    public function row(ProposalItem $item): ?ProposalForecastRow
    {
        return $this->rows[$item->getKey()] ?? null;
    }

    /**
     * «Proyección de Nexus del 17/11/2026 a las 6:10 · últimos 4 Ene-Jun · 35 por grupo, 25 con laboratorio», o que no hay.
     */
    public function headerLine(string $timezone): string
    {
        if ($this->computedAt === null) {
            return 'Sin proyección de Nexus para esta propuesta.';
        }

        $sizes = $this->settings->groupSizes;

        return "Proyección de Nexus del {$this->computedAtText($timezone)} · últimos {$this->settings->historyTerms} {$this->termType()} · {$sizes->defaultSize} por grupo, {$sizes->labSize} con laboratorio";
    }

    /**
     * «17/11/2026 a las 6:10» en la zona de la página.
     */
    public function computedAtText(string $timezone): string
    {
        $at = $this->computedAt?->setTimezone(new \DateTimeZone($timezone));

        return $at === null ? '' : $at->format('d/m/Y').' a las '.$at->format('G:i');
    }

    /**
     * El tipo del periodo que mira el historial: Ene-Jun, Ago-Dic o Verano.
     */
    public function termType(): string
    {
        return match (substr($this->termCode, -1)) {
            '1' => 'Ene-Jun',
            '2' => 'Verano',
            default => 'Ago-Dic',
        };
    }

    /**
     * Las filas por revisar de esas columnas, en el orden de la propuesta.
     *
     * @param  list<string>  $keys
     * @return list<ProposalForecastRow>
     */
    public function pending(array $keys, ?ForecastReason $reason = null): array
    {
        return array_values(array_filter($this->rows, fn (ProposalForecastRow $row): bool => $this->inColumns($row, $keys)
            && $row->isPending()
            && ($reason === null || $row->has($reason))));
    }

    /**
     * Las filas ya revisadas de esas columnas (con la marca y sin nada pendiente), en el orden de la propuesta.
     *
     * @param  list<string>  $keys
     * @return list<ProposalForecastRow>
     */
    public function reviewed(array $keys, ?ForecastReason $reason = null): array
    {
        return array_values(array_filter($this->rows, fn (ProposalForecastRow $row): bool => $this->inColumns($row, $keys)
            && $row->isReviewed()
            && ($reason === null || $row->has($reason))));
    }

    /**
     * Cuántas filas por revisar hay en esas columnas: todas ('') y por motivo (ForecastReason::filter()). Una fila con dos
     * motivos cuenta en los dos.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public function counts(array $keys): array
    {
        $pending = $this->pending($keys);
        $counts = ['' => count($pending)];

        foreach (ForecastReason::cases() as $reason) {
            $counts[$reason->filter()] = count(array_filter($pending, fn (ProposalForecastRow $row): bool => $row->has($reason)));
        }

        return $counts;
    }

    /**
     * Cuántas filas de esas columnas corregidas tienen la proyección cambiada: lo que dice el aviso de recálculo.
     *
     * @param  list<string>  $keys
     */
    public function changed(array $keys): int
    {
        return count($this->pending($keys, ForecastReason::Changed));
    }

    /**
     * La marca de una materia en la tabla y en la retícula, de sus filas en esas columnas: `state` es `changed` si alguna
     * cambió, `captured` si alguna se corrigió, `suggested` si todas siguen lo sugerido y null sin proyección; `large` si
     * alguna se aparta del sugerido; `review`, lo pendiente: la primera fila pendiente con su motivo principal y cuántos
     * motivos más hay entre las filas pendientes de la materia.
     *
     * @param  list<string>  $keys
     * @return array{state: ?string, large: bool, review: array{reason: ForecastReason, more: int, item: string}|null}
     */
    public function mark(ProposalSubject $subject, array $keys): array
    {
        $rows = array_values(array_filter(array_map(fn (ProposalItem $item): ?ProposalForecastRow => $this->row($item), $subject->items()),
            fn (?ProposalForecastRow $row): bool => $row !== null && $this->inColumns($row, $keys)));
        $states = array_map(fn (ProposalForecastRow $row): SuggestionState => $row->suggestion->state, $rows);
        $pending = array_values(array_filter($rows, fn (ProposalForecastRow $row): bool => $row->isPending()));
        $reasons = array_values(array_filter(ForecastReason::cases(), fn (ForecastReason $reason): bool => array_any($pending, fn (ProposalForecastRow $row): bool => $row->has($reason))));

        return [
            'state' => match (true) {
                in_array(SuggestionState::Changed, $states, true) => 'changed',
                in_array(SuggestionState::Captured, $states, true) => 'captured',
                in_array(SuggestionState::Suggested, $states, true) => 'suggested',
                default => null,
            },
            'large' => array_any($rows, fn (ProposalForecastRow $row): bool => $row->isLargeDifference()),
            'review' => $pending === [] ? null : ['reason' => $pending[0]->primary(), 'more' => count($reasons) - 1, 'item' => $pending[0]->item->getKey()],
        ];
    }

    /**
     * @param  list<string>  $keys
     */
    private function inColumns(ProposalForecastRow $row, array $keys): bool
    {
        return in_array(ProposalOverview::columnKey($row->campus), $keys, true);
    }
}
