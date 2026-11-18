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
 * Borra un paquete de la propuesta (tarea 5.6b). El borrado es físico, con sus materias y sus columnas: un paquete no es
 * catálogo, es parte de una propuesta en borrador (supuesto por confirmar; la bitácora conserva lo que fue). Mismas guardas
 * que CreateProposalPackage sobre sus materias. Recalcula `package_groups` de esas materias, así que dejan de abrir grupos
 * por el paquete (los sueltos se conservan), y los paquetes que quedan vuelven a calcular su sugerido (el de menor posición
 * es ahora el primero). Quién puede lo decide CareerProposalPolicy::managePackages.
 */
final class DeleteProposalPackage
{
    public function __construct(
        private readonly ProposalPackageGuard $guard,
        private readonly SyncPackageCounts $sync,
        private readonly RecalculatePackageGroups $recalculate,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(ProposalPackage $package, User $actor): void
    {
        DB::transaction(function () use ($package, $actor): void {
            $proposal = $this->guard->lockEditable($package->career_proposal_id);
            $package = ProposalPackage::query()->with(['subjects', 'campuses'])->lockForUpdate()->findOrFail($package->getKey());
            $subjectIds = $package->subjects->pluck('subject_id')->all();

            $this->guard->ensureOpen($proposal, $subjectIds);

            $old = ['code' => $package->code, 'name' => $package->name, 'subject_ids' => $subjectIds, 'counts' => $package->campuses->pluck('count', 'campus_id')->all()];
            $package->delete();

            $this->recalculate->handle($proposal, $subjectIds, $actor);
            $this->sync->handle($proposal, $actor);

            activity()
                ->performedOn($proposal)
                ->causedBy($actor)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $old])
                ->withProperties(['career_proposal_id' => $proposal->getKey(), 'package_id' => $package->getKey()])
                ->log("Borró el paquete {$old['code']} de la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalPackagesChanged::dispatch($proposal->getKey(), null);
        });
    }
}
