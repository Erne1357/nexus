<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Events\ProposalPackagesChanged;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Rules\ProposalPackageGuard;
use App\Modules\AcademicLoad\Rules\ProposalPackageRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone o quita el nombre opcional de un paquete («Matutino»; tarea 5.6b). Solo en una propuesta en borrador o regresada;
 * el nombre no mueve grupos, así que no mira las partes de los departamentos. Quién puede lo decide
 * CareerProposalPolicy::managePackages. Sin cambios no escribe.
 */
final class RenameProposalPackage
{
    public function __construct(private readonly ProposalPackageGuard $guard, private readonly ProposalPackageRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(ProposalPackage $package, ?string $name, User $actor): ProposalPackage
    {
        $name = $this->rules->normalizeName($name);

        return DB::transaction(function () use ($package, $name, $actor): ProposalPackage {
            $proposal = $this->guard->lockEditable($package->career_proposal_id);
            $package = ProposalPackage::query()->lockForUpdate()->findOrFail($package->getKey());

            if ($package->name === $name) {
                return $package;
            }

            $old = $package->name;
            $package->update(['name' => $name]);

            activity()
                ->performedOn($package)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['name' => $name], 'old' => ['name' => $old]])
                ->withProperties(['career_proposal_id' => $proposal->getKey()])
                ->log("Renombró el paquete {$package->code} en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalPackagesChanged::dispatch($proposal->getKey(), $package->getKey());

            return $package;
        });
    }
}
