<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\ProposalSuggestionsSynced;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone al día las propuestas con la proyección recalculada de Demand (tarea 5.5; DemandForecastsComputed). Para el
 * periodo y la modalidad, las carreras dadas y, si viene, un solo campus, toma las propuestas en borrador o regresadas
 * (una enviada o aceptada no se toca). En cada una, las filas que el coordinador no ha tocado (`captured_at` nulo) y cuyo
 * departamento no aceptó su parte siguen a la proyección: esperados, grupos, `suggested_*` y `forecast_method`. Las que
 * corrigió no se tocan: el aviso «la proyección cambió» se calcula al leer (ProposalSuggestionState). Una fila sin
 * tocar que se queda sin proyección no cambia, y una que ya coincide no se escribe.
 *
 * Una transacción por propuesta, que la bloquea (como UpdateProposalItem: propuesta y luego sus partes) y escribe con un
 * solo UPDATE por lotes, así que las consultas no crecen con las filas. Deja una entrada de bitácora por propuesta, sin
 * causante (lo hizo Nexus), y, después del commit, ProposalSuggestionsSynced. Devuelve cuántas filas se pusieron al día.
 */
final class SyncProposalSuggestions
{
    /**
     * @param  list<string>  $careerIds
     */
    public function handle(string $termId, string $modalityId, ?string $campusId, array $careerIds): int
    {
        $proposalIds = CareerProposal::query()
            ->where('term_id', $termId)
            ->where('modality_id', $modalityId)
            ->whereIn('career_id', $careerIds)
            ->whereIn('status', array_map(fn (ProposalStatus $status): string => $status->value, array_filter(ProposalStatus::cases(), fn (ProposalStatus $status): bool => $status->isEditable())))
            ->pluck('id');

        $total = 0;

        foreach ($proposalIds as $proposalId) {
            $total += $this->syncProposal($proposalId, $campusId);
        }

        return $total;
    }

    private function syncProposal(string $proposalId, ?string $campusId): int
    {
        return DB::transaction(function () use ($proposalId, $campusId): int {
            $proposal = CareerProposal::query()
                ->with(['career' => fn ($query) => $query->withTrashed(), 'modality' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->find($proposalId);

            if ($proposal === null || ! $proposal->status->isEditable()) {
                return 0;
            }

            $accepted = ProposalReview::query()
                ->where('career_proposal_id', $proposal->getKey())
                ->where('status', ProposalReviewStatus::Accepted)
                ->lockForUpdate()
                ->pluck('department_id')
                ->all();

            $bindings = [$proposal->term_id, $proposal->modality_id, $proposal->career_id, $proposal->getKey()];
            $campusFilter = '';

            if ($campusId !== null) {
                $campusFilter = ' and proposal_items.campus_id = ?';
                $bindings[] = $campusId;
            }

            $acceptedFilter = '';

            if ($accepted !== []) {
                $acceptedFilter = ' and subjects.department_id not in ('.implode(', ', array_fill(0, count($accepted), '?')).')';
                array_push($bindings, ...$accepted);
            }

            $count = DB::update(<<<SQL
                update proposal_items
                set expected_students = f.expected_students,
                    requested_groups = f.suggested_groups,
                    suggested_expected_students = f.expected_students,
                    suggested_groups = f.suggested_groups,
                    forecast_method = f.method,
                    updated_at = now()
                from demand_forecasts f, subjects
                where f.term_id = ?
                    and f.modality_id = ?
                    and f.career_id = ?
                    and proposal_items.career_proposal_id = ?
                    and f.subject_id = proposal_items.subject_id
                    and f.campus_id is not distinct from proposal_items.campus_id
                    and subjects.id = proposal_items.subject_id
                    and proposal_items.captured_at is null
                    and (
                        proposal_items.expected_students is distinct from f.expected_students
                        or proposal_items.requested_groups is distinct from f.suggested_groups
                        or proposal_items.suggested_expected_students is distinct from f.expected_students
                        or proposal_items.suggested_groups is distinct from f.suggested_groups
                        or proposal_items.forecast_method is distinct from f.method
                    ){$campusFilter}{$acceptedFilter}
                SQL, $bindings);

            if ($count === 0) {
                return 0;
            }

            activity()
                ->performedOn($proposal)
                ->event(ActivityEvent::Updated)
                ->withProperties(['items' => $count, 'campus_id' => $campusId])
                ->log("Nexus actualizó {$count} materias con la proyección en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalSuggestionsSynced::dispatch($proposal, $count);

            return $count;
        });
    }
}
