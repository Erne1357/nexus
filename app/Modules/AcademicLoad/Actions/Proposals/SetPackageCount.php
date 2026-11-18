<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Events\ProposalPackagesChanged;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;
use App\Modules\AcademicLoad\Rules\ProposalPackageGuard;
use App\Modules\AcademicLoad\Rules\ProposalPackageRules;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Captura cuántos paquetes de un tipo abre un campus (tarea 5.6b; confirmado el 16 y 17 de noviembre de 2026: cada campus
 * captura cuántos abre de cada paquete y cada paquete abre un grupo de cada una de sus materias). De 0 a 30. La cuenta queda
 * marcada como corregida (`captured_at`, `captured_by`): lo sugerido se guarda aparte y la cuenta ya no lo sigue sola. Sin
 * cambios no escribe.
 *
 * En una transacción bloquea la propuesta (en borrador o regresada) y las partes de los departamentos de las materias del
 * paquete: si alguno ya aceptó la suya, una ValidationException sobre `package`. Luego recalcula `package_groups` de esas
 * materias en ese campus (RecalculatePackageGroups). Quién puede lo decide CareerProposalPolicy::updateColumn antes de
 * llamarla: es la misma regla que corregir una fila (M7), así que un coordinador con campus solo cambia la suya.
 */
final class SetPackageCount
{
    public function __construct(
        private readonly ProposalPackageGuard $guard,
        private readonly ProposalPackageRules $rules,
        private readonly RecalculatePackageGroups $recalculate,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(ProposalPackage $package, ?Campus $campus, int $count, User $actor): ProposalPackageCampus
    {
        $this->rules->ensureCount($count);

        return DB::transaction(function () use ($package, $campus, $count, $actor): ProposalPackageCampus {
            $proposal = $this->guard->lockEditable($package->career_proposal_id);
            $package = ProposalPackage::query()->with('subjects')->lockForUpdate()->findOrFail($package->getKey());

            $column = ProposalPackageCampus::query()
                ->where('proposal_package_id', $package->getKey())
                ->where(fn ($query) => $campus === null ? $query->whereNull('campus_id') : $query->where('campus_id', $campus->getKey()))
                ->lockForUpdate()
                ->first();

            if ($column === null) {
                throw ValidationException::withMessages(['campus' => 'Esa columna no está en la propuesta.']);
            }

            if ($column->count === $count) {
                return $column;
            }

            $subjectIds = $package->subjects->pluck('subject_id')->all();
            $this->guard->ensureOpen($proposal, $subjectIds);

            $old = $column->count;
            $column->forceFill(['count' => $count, 'captured_at' => now(), 'captured_by' => $actor->getKey()])->save();

            $this->recalculate->handle($proposal, $subjectIds, $actor);

            activity()
                ->performedOn($column)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['count' => $count], 'old' => ['count' => $old]])
                ->withProperties(['career_proposal_id' => $proposal->getKey(), 'proposal_package_id' => $package->getKey(), 'campus_id' => $column->campus_id])
                ->log("Cambió a {$count} los paquetes {$package->code}".($campus === null ? '' : " de {$campus->code}")." en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalPackagesChanged::dispatch($proposal->getKey(), $package->getKey());

            return $column;
        });
    }
}
