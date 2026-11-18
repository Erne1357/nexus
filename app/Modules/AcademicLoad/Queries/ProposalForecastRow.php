<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\ForecastReason;
use App\Modules\AcademicLoad\Enums\SuggestionState;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Rules\ProposalSuggestionState;
use App\Modules\Core\Models\Campus;
use App\Modules\Demand\Models\DemandForecast;

/**
 * Una fila de la propuesta (materia × campus) frente a la proyección vigente de Demand (tarea 5.6; ProposalForecastQuery):
 * su estado (ProposalSuggestionState), la fila vigente de la proyección (null si ya no hay) y sus motivos para revisar
 * (ForecastReason, el primero manda sobre las acciones).
 *
 * Está pendiente si la proyección cambió (siempre: el cambio es nuevo aunque la fila se haya revisado antes) o si tiene
 * motivo y nadie la ha revisado (`reviewed_at` nulo). Revisada es la que tiene la marca y ya no está pendiente
 * (supuesto por confirmar).
 */
final readonly class ProposalForecastRow
{
    /**
     * @param  list<ForecastReason>  $reasons
     */
    public function __construct(
        public ProposalItem $item,
        public ProposalSubject $subject,
        public ?Campus $campus,
        public ProposalSuggestionState $suggestion,
        public ?DemandForecast $forecast,
        public array $reasons,
    ) {}

    public function isPending(): bool
    {
        return $this->suggestion->state === SuggestionState::Changed || ($this->reasons !== [] && $this->item->reviewed_at === null);
    }

    public function isReviewed(): bool
    {
        return ! $this->isPending() && $this->item->reviewed_at !== null;
    }

    public function primary(): ?ForecastReason
    {
        return $this->reasons[0] ?? null;
    }

    public function has(ForecastReason $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }

    /**
     * Los esperados que sugiere Nexus ahora: los de la proyección vigente o, si ya no hay, los guardados.
     */
    public function suggestedExpected(): ?int
    {
        return $this->forecast?->expected_students ?? $this->item->suggested_expected_students;
    }

    /**
     * Los grupos que sugiere Nexus ahora: los de la proyección vigente o, si ya no hay, los guardados.
     */
    public function suggestedGroups(): ?int
    {
        return $this->forecast?->suggested_groups ?? $this->item->suggested_groups;
    }

    /**
     * Los grupos que sugiere Nexus para esta fila: sin paquetes, los sugeridos; con paquetes (tarea 5.6b), lo sugerido
     * efectivo, los de paquetes más los sueltos sugeridos (SuggestedRowGroups, ProposalForecastQuery::stateFor). Es contra lo
     * que se mide la diferencia grande de grupos y lo que escribe UpdateProposalItem::useSuggested.
     */
    public function effectiveGroups(): ?int
    {
        return $this->suggestion->effectiveGroups ?? $this->suggestedGroups();
    }

    /**
     * Con paquetes, de qué se forma lo sugerido efectivo: los grupos de paquetes y los sueltos sugeridos; null sin paquetes.
     *
     * @return array{packages: int, loose: int}|null
     */
    public function packageSplit(): ?array
    {
        $effective = $this->suggestion->effectiveGroups;

        return $effective === null ? null : ['packages' => $this->item->package_groups, 'loose' => max(0, $effective - $this->item->package_groups)];
    }

    /**
     * Si la fila está corregida (o cambió) y se aparta del sugerido en esperados o en grupos: solo se resalta.
     */
    public function isLargeDifference(): bool
    {
        return $this->suggestion->largeDifferenceExpected || $this->suggestion->largeDifferenceGroups;
    }

    /**
     * Cuánto se aparta lo capturado del sugerido, en porcentaje con signo («+50 %»), de lo que se resalta: grupos si se
     * resaltan, si no esperados; null si nada se resalta.
     *
     * @return array{field: 'groups'|'expected', percent: string}|null
     */
    public function difference(): ?array
    {
        [$field, $captured, $suggested] = match (true) {
            $this->suggestion->largeDifferenceGroups => ['groups', $this->item->requested_groups, $this->effectiveGroups()],
            $this->suggestion->largeDifferenceExpected => ['expected', $this->item->expected_students, $this->suggestedExpected()],
            default => [null, 0, null],
        };

        if ($field === null || $suggested === null) {
            return null;
        }

        if ($suggested === 0) {
            return ['field' => $field, 'percent' => 'sin sugerido'];
        }

        $percent = (int) round(($captured - $suggested) * 100 / $suggested);

        return ['field' => $field, 'percent' => ($percent > 0 ? '+' : ($percent < 0 ? '−' : '')).abs($percent).' %'];
    }
}
