<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Events\ProposalPackagesChanged;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Rules\ProposalPackageGuard;
use App\Modules\AcademicLoad\Rules\ProposalPackageRules;
use App\Modules\Demand\Queries\DemandForecastsQuery;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone al día el nuevo ingreso de las propuestas con la proyección recalculada de Demand (tareas 5.5 y 5.6b;
 * DemandForecastsComputed), junto a SyncProposalSuggestions. Para el periodo y la modalidad, las carreras dadas y, si
 * viene, un solo campus, toma las propuestas en borrador o regresadas (una enviada o aceptada no se toca). En cada una,
 * `suggested_new_students` de cada columna es el de la proyección (ProposalPackageRules::suggestedNewStudents); las columnas
 * sin tocar (`captured_at` nulo) siguen a él en `new_students` y las corregidas conservan lo capturado. Sin proyección de
 * primer semestre en la columna no cambia nada. Después pone al día los paquetes sugeridos y los que siguen a ellos
 * (SyncPackageCounts).
 *
 * Una transacción por propuesta, que la bloquea. Deja una entrada de bitácora por propuesta con cambios, sin causante (lo
 * hizo Nexus), y avisa con ProposalPackagesChanged. Devuelve cuántas columnas de nuevo ingreso cambió.
 */
final class SyncProposalIntakes
{
    public function __construct(
        private readonly DemandForecastsQuery $forecasts,
        private readonly ProposalPackageGuard $guard,
        private readonly ProposalPackageRules $rules,
        private readonly SyncPackageCounts $sync,
    ) {}

    /**
     * @param  list<string>  $careerIds
     */
    public function handle(string $termId, string $modalityId, ?string $campusId, array $careerIds): int
    {
        $proposalIds = CareerProposal::query()
            ->where('term_id', $termId)
            ->where('modality_id', $modalityId)
            ->whereIn('career_id', $careerIds)
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
            $proposal = CareerProposal::query()->lockForUpdate()->find($proposalId);

            if ($proposal === null || ! $proposal->status->isEditable()) {
                return 0;
            }

            $proposal = $this->guard->lockEditable($proposalId);
            $forecasts = $this->forecasts->forCareer($proposal->term()->firstOrFail(), $proposal->modality, $proposal->career);

            $count = 0;

            $intakes = ProposalIntake::query()
                ->where('career_proposal_id', $proposal->getKey())
                ->when($campusId !== null, fn ($query) => $query->where('campus_id', $campusId))
                ->lockForUpdate()
                ->get();

            foreach ($intakes as $intake) {
                $suggested = $this->rules->suggestedNewStudents($forecasts, $intake->campus_id);

                if ($suggested === null) {
                    continue;
                }

                $follows = $intake->captured_at === null;

                if ($intake->suggested_new_students === $suggested && (! $follows || $intake->new_students === $suggested)) {
                    continue;
                }

                $intake->suggested_new_students = $suggested;

                if ($follows) {
                    $intake->new_students = $suggested;
                }

                $intake->save();
                $count++;
            }

            $moved = $this->sync->handle($proposal, null);

            if ($count === 0 && $moved === 0) {
                return 0;
            }

            activity()
                ->performedOn($proposal)
                ->event(ActivityEvent::Updated)
                ->withProperties(['intakes' => $count, 'package_counts' => $moved, 'campus_id' => $campusId])
                ->log("Nexus actualizó el nuevo ingreso y los paquetes en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalPackagesChanged::dispatch($proposal->getKey());

            return $count;
        });
    }
}
