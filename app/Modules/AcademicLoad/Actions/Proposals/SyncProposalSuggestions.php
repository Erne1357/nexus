<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\ProposalSuggestionsSynced;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\Demand\Rules\SuggestedGroups;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone al día las propuestas con la proyección recalculada de Demand (tarea 5.5; DemandForecastsComputed). Para el
 * periodo y la modalidad, las carreras dadas y, si viene, un solo campus, toma las propuestas en borrador o regresadas
 * (una enviada o aceptada no se toca). En cada una, las filas que el coordinador no ha tocado (`captured_at` nulo) y cuyo
 * departamento no aceptó su parte siguen a la proyección: esperados, grupos, `suggested_*` y `forecast_method`. Con paquetes en la fila (`package_groups > 0`), los grupos son los de paquetes más los sueltos sugeridos, `ceil(max(0, esperados - nuevo ingreso del campus) / tamaño)` (tarea 5.6b), en el mismo UPDATE: solo resta el nuevo ingreso si el método es `first_semester` (solo ahí los esperados lo incluyen), `package_groups` lo recalcula el mismo UPDATE desde las cuentas, y el total se recorta al tope de grupos de una fila (sin bajar de `package_groups`), dejando en la bitácora cuántas filas se recortaron. Esta Action es la dueña de las filas sin tocar con proyección. Las que
 * corrigió no se tocan: el aviso «la proyección cambió» se calcula al leer (ProposalSuggestionState). Si el coordinador
 * corrigió el nuevo ingreso de la columna (`proposal_intakes.captured_at`), los esperados de una fila sin tocar de primer
 * semestre (`first_semester`) son los de la proyección menos su `new_students` más el nuevo ingreso vigente, y sus grupos
 * salen de esos esperados: los de paquetes más los sueltos o, sin paquetes, los de SuggestedGroups (supuesto por confirmar,
 * pedido el 17 de noviembre de 2026); lo sugerido (`suggested_*`) sigue siendo el de la proyección. UpdateProposalIntake la
 * llama para su propuesta al corregir el nuevo ingreso. Una fila sin
 * tocar que se queda sin proyección no cambia, y una que ya coincide no se escribe. Si lo sugerido de una fila cambia, su
 * marca de revisada se borra (`reviewed_*`, «Revisar proyección», tarea 5.6): lo que se revisó ya no es lo que sugiere.
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

            $sizes = SuggestedGroups::fromSettings();
            $max = ProposalItemRules::MAX_REQUESTED_GROUPS;
            $packageGroups = <<<'SQL'
                case when proposal_items.campus_id is null or exists (
                    select 1 from campuses c where c.id = proposal_items.campus_id and c.is_active and c.deleted_at is null
                ) then coalesce((
                    select sum(pc.count) from proposal_package_campuses pc
                    join proposal_packages p on p.id = pc.proposal_package_id
                    join proposal_package_subjects ps on ps.proposal_package_id = p.id
                    where p.career_proposal_id = proposal_items.career_proposal_id
                        and ps.subject_id = proposal_items.subject_id
                        and pc.campus_id is not distinct from proposal_items.campus_id
                ), 0)::int else proposal_items.package_groups end
                SQL;
            // El nuevo ingreso corregido de la columna (null si sigue a la proyección): mueve los esperados de las filas sin tocar
            // de primer semestre (supuesto por confirmar, pedido el 17 de noviembre de 2026).
            $correctedIntake = <<<'SQL'
                (select i.new_students from proposal_intakes i
                    where i.career_proposal_id = proposal_items.career_proposal_id and i.campus_id is not distinct from proposal_items.campus_id
                        and i.captured_at is not null)
                SQL;
            $expected = <<<SQL
                case when f.method = 'first_semester' and ({$correctedIntake}) is not null
                    then greatest(0, f.expected_students - coalesce(f.new_students, 0) + ({$correctedIntake}))
                    else f.expected_students end
                SQL;
            $size = "case when subjects.needs_lab then {$sizes->labSize} else {$sizes->defaultSize} end";
            $wanted = <<<SQL
                case when ({$packageGroups}) > 0
                    then ({$packageGroups}) + ceil(greatest(0, ({$expected}) - case when f.method = 'first_semester' then coalesce((
                        select i.new_students from proposal_intakes i
                        where i.career_proposal_id = proposal_items.career_proposal_id and i.campus_id is not distinct from proposal_items.campus_id
                    ), 0) else 0 end)::numeric / {$size})::int
                    when ({$expected}) is distinct from f.expected_students then ceil(({$expected})::numeric / {$size})::int
                    else f.suggested_groups end
                SQL;
            $groups = "greatest(({$packageGroups}), least({$max}, {$wanted}))";

            $rows = DB::select(<<<SQL
                update proposal_items
                set expected_students = {$expected},
                    package_groups = {$packageGroups},
                    requested_groups = {$groups},
                    suggested_expected_students = f.expected_students,
                    suggested_groups = f.suggested_groups,
                    forecast_method = f.method,
                    reviewed_at = case when (proposal_items.suggested_expected_students, proposal_items.suggested_groups) is distinct from (f.expected_students, f.suggested_groups) then null else proposal_items.reviewed_at end,
                    reviewed_by = case when (proposal_items.suggested_expected_students, proposal_items.suggested_groups) is distinct from (f.expected_students, f.suggested_groups) then null else proposal_items.reviewed_by end,
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
                        proposal_items.expected_students is distinct from ({$expected})
                        or proposal_items.requested_groups is distinct from {$groups}
                        or proposal_items.package_groups is distinct from {$packageGroups}
                        or proposal_items.suggested_expected_students is distinct from f.expected_students
                        or proposal_items.suggested_groups is distinct from f.suggested_groups
                        or proposal_items.forecast_method is distinct from f.method
                    ){$campusFilter}{$acceptedFilter}
                returning ({$wanted}) as wanted
                SQL, $bindings);

            $count = count($rows);
            $capped = count(array_filter($rows, fn (object $row): bool => (int) $row->wanted > $max));

            if ($count === 0) {
                return 0;
            }

            activity()
                ->performedOn($proposal)
                ->event(ActivityEvent::Updated)
                ->withProperties(['items' => $count, 'campus_id' => $campusId, 'capped_at_max' => $capped])
                ->log("Nexus actualizó {$count} materias con la proyección en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalSuggestionsSynced::dispatch($proposal, $count);

            return $count;
        });
    }
}
