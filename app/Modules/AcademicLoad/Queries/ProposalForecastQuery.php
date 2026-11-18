<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\AcademicLoad\Enums\ForecastReason;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Rules\ProposalSuggestionState;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Queries\DemandForecastsQuery;
use App\Modules\Demand\Queries\TermOccupancyQuery;
use App\Modules\Demand\Rules\DemandSettings;

/**
 * La propuesta de carrera frente a la proyección de Demand, para «Revisar proyección» y las marcas de la tabla y la
 * retícula (tarea 5.6, opción C elegida por el usuario el 17 de noviembre de 2026). Quién la ve lo decide
 * CareerProposalPolicy en la página.
 *
 * - review(): el estado y los motivos de cada fila con la proyección vigente de la carrera (DemandForecastsQuery::forCareer,
 *   una consulta para toda la propuesta).
 * - history(): el historial corto de los periodos del mismo tipo de las filas que se pintan (las tarjetas a la vista y el
 *   panel abierto): grupos, lugares e inscritos de SubjectGroupHistoryQuery y reprobados del kárdex de
 *   TermOccupancyQuery, cada uno llamado una vez por lote (tres consultas).
 */
final class ProposalForecastQuery
{
    public function __construct(
        private readonly DemandForecastsQuery $forecasts,
        private readonly SubjectGroupHistoryQuery $groupHistory,
        private readonly TermOccupancyQuery $occupancy,
    ) {}

    /**
     * Cada fila de la propuesta con su estado (ProposalSuggestionState) y sus motivos (ForecastReason::for): `changed` si la
     * proyección cambió; `large_difference` si está corregida y se aparta del sugerido; y, sin tocar, `specialty` si es de
     * una especialidad del plan, `no_uptake` si la proyección la marca sin tomas recientes y `no_history` si no tiene
     * historia de la materia (la tasa del departamento o ninguna). `computedAt` es el cálculo más reciente de la proyección de la carrera. Sin proyección para la propuesta no
     * hay filas: la página se ve como antes de la 5.6.
     */
    public function review(ProposalOverview $overview, DemandSettings $settings): ProposalForecastReview
    {
        $proposal = $overview->proposal;
        $forecasts = $this->forecasts->forCareer($proposal->term, $proposal->modality, $proposal->career);
        $rows = [];

        if ($forecasts->isEmpty()) {
            return new ProposalForecastReview(null, $settings, $proposal->term->code, $proposal->modality, []);
        }

        foreach ($overview->subjects() as $subject) {
            $specialty = $overview->specialtyFor($subject) !== null;

            foreach ($overview->columns as $campus) {
                $item = $subject->cell($campus);

                if ($item === null) {
                    continue;
                }

                /** @var DemandForecast|null $forecast */
                $forecast = $forecasts->get($item->subject_id)?->get(ProposalOverview::columnKey($campus));
                $suggestion = ProposalSuggestionState::of(
                    $item->expected_students,
                    $item->requested_groups,
                    $item->suggested_expected_students,
                    $item->suggested_groups,
                    $item->captured_at,
                    $forecast?->expected_students,
                    $forecast?->suggested_groups,
                    $settings,
                );

                $rows[$item->getKey()] = new ProposalForecastRow($item, $subject, $campus, $suggestion, $forecast, ForecastReason::for($suggestion, $forecast, $specialty));
            }
        }

        $computedAt = $forecasts->flatten(1)->max(fn (DemandForecast $forecast) => $forecast->computed_at);

        return new ProposalForecastReview($computedAt, $settings, $proposal->term->code, $proposal->modality, $rows);
    }

    /**
     * La fila vigente de la proyección para una fila de la propuesta (su periodo, modalidad, carrera, materia y campus), o null.
     * La usan las Actions de «Revisar proyección» para revisar el estado bajo bloqueo.
     */
    public function currentFor(CareerProposal $proposal, ProposalItem $item): ?DemandForecast
    {
        return DemandForecast::query()
            ->where('term_id', $proposal->term_id)
            ->where('modality_id', $proposal->modality_id)
            ->where('career_id', $proposal->career_id)
            ->where('subject_id', $item->subject_id)
            ->where(fn ($query) => $item->campus_id === null ? $query->whereNull('campus_id') : $query->where('campus_id', $item->campus_id))
            ->first();
    }

    /**
     * Si la materia de la fila es de una especialidad en el plan de la fila, como en CareerProposalQuery::overview.
     */
    public function isSpecialty(ProposalItem $item): bool
    {
        return StudyPlanSubject::withTrashed()
            ->where('study_plan_id', $item->study_plan_id)
            ->where('subject_id', $item->subject_id)
            ->whereNotNull('specialty_id')
            ->exists();
    }

    /**
     * El nuevo ingreso vigente de cada columna de la propuesta (`proposal_intakes`, 5.6b), por ProposalOverview::columnKey:
     * con él se calculan los grupos sueltos de una fila con paquetes al usar lo sugerido.
     *
     * @return array<string, int>
     */
    public function intakes(CareerProposal $proposal): array
    {
        return ProposalIntake::query()
            ->where('career_proposal_id', $proposal->getKey())
            ->get(['campus_id', 'new_students'])
            ->mapWithKeys(fn (ProposalIntake $intake): array => [(string) $intake->campus_id => (int) $intake->new_students])
            ->all();
    }

    /**
     * El historial corto de cada fila en su campus, por id de fila: un renglón por periodo del mismo tipo que el de la
     * propuesta (los `demand.history_terms` anteriores), del más antiguo al más reciente, con los grupos, lugares e
     * inscritos de los grupos abiertos y los que reprobaron según el kárdex; null donde no hay dato. Tres consultas.
     *
     * @param  list<ProposalForecastRow>  $rows
     * @return array<string, list<array{term: string, label: string, groups: ?int, capacity: ?int, enrolled: ?int, failed: ?int}>>
     */
    public function history(ProposalForecastReview $review, array $rows): array
    {
        $terms = array_reverse(SubjectGroupHistoryQuery::sameTypeTermsBefore($review->termCode, $review->settings->historyTerms));

        if ($rows === [] || $terms === []) {
            return [];
        }

        $subjectIds = array_values(array_unique(array_map(fn (ProposalForecastRow $row): string => $row->item->subject_id, $rows)));
        $groups = $this->groupHistory->history($review->termCode, $review->modality, $subjectIds, $review->settings->historyTerms);
        $occupancy = $this->occupancy->history($review->termCode, $review->modality, $subjectIds, $review->settings->historyTerms);
        $history = [];

        foreach ($rows as $row) {
            $subjectId = $row->item->subject_id;
            $groupsKey = $row->item->campus_id ?? SubjectGroupHistoryQuery::NO_CAMPUS;
            $occupancyKey = $row->item->campus_id ?? '';

            $history[$row->item->getKey()] = array_map(function (string $term) use ($groups, $occupancy, $subjectId, $groupsKey, $occupancyKey): array {
                $opened = $groups[$subjectId][$term][$groupsKey] ?? null;

                return [
                    'term' => $term,
                    'label' => substr($term, 0, 4),
                    'groups' => $opened['groups'] ?? null,
                    'capacity' => $opened['capacity'] ?? null,
                    'enrolled' => $opened['enrolled'] ?? null,
                    'failed' => ($occupancy[$subjectId][$term][$occupancyKey] ?? null)?->failed,
                ];
            }, $terms);
        }

        return $history;
    }
}
