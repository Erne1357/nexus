<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Events\ProposalPackagesChanged;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Rules\ProposalPackageGuard;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia las materias de un paquete (tarea 5.6b; confirmado el 16 y 17 de noviembre de 2026: qué materias lleva cada paquete
 * lo arma el coordinador). Mismas guardas que CreateProposalPackage, sobre las materias que se quitan y las que se ponen:
 * propuesta en borrador o regresada, materias con fila en la propuesta, al menos una, y ninguna de un departamento que ya
 * aceptó su parte. Recalcula `package_groups` de todas las afectadas, así que las que se quitan dejan de abrir grupos por el
 * paquete y las que entran empiezan a abrirlos. Sin cambios no escribe ni avisa. Quién puede lo decide
 * CareerProposalPolicy::managePackages.
 */
final class UpdateProposalPackageSubjects
{
    public function __construct(
        private readonly ProposalPackageGuard $guard,
        private readonly SyncPackageCounts $sync,
        private readonly RecalculatePackageGroups $recalculate,
    ) {}

    /**
     * @param  list<string>  $subjectIds
     *
     * @throws ValidationException
     */
    public function handle(ProposalPackage $package, array $subjectIds, User $actor): ProposalPackage
    {
        $subjectIds = array_values(array_unique($subjectIds));

        return DB::transaction(function () use ($package, $subjectIds, $actor): ProposalPackage {
            $proposal = $this->guard->lockEditable($package->career_proposal_id);
            $package = ProposalPackage::query()->lockForUpdate()->findOrFail($package->getKey());

            if ($subjectIds === []) {
                throw ValidationException::withMessages(['subjects' => 'Elige al menos una materia para el paquete.']);
            }

            $current = $package->subjects()->pluck('subject_id')->all();
            $added = array_values(array_diff($subjectIds, $current));
            $removed = array_values(array_diff($current, $subjectIds));

            if ($added === [] && $removed === []) {
                return $package->load(['subjects', 'campuses']);
            }

            $this->guard->ensureInProposal($proposal, $added);
            $this->guard->ensureOpen($proposal, [...$added, ...$removed]);

            $package->subjects()->whereIn('subject_id', $removed)->delete();
            $package->subjects()->createMany(array_map(fn (string $subjectId): array => ['subject_id' => $subjectId], $added));

            $this->recalculate->handle($proposal, [...$added, ...$removed], $actor);
            $this->sync->handle($proposal, $actor);

            activity()
                ->performedOn($package)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['subject_ids' => $subjectIds], 'old' => ['subject_ids' => $current]])
                ->withProperties(['career_proposal_id' => $proposal->getKey(), 'added' => $added, 'removed' => $removed])
                ->log("Cambió las materias del paquete {$package->code} en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalPackagesChanged::dispatch($proposal->getKey(), $package->getKey());

            return $package->load(['subjects', 'campuses']);
        });
    }
}
