<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Events\ProposalPackagesChanged;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Rules\ProposalPackageGuard;
use App\Modules\AcademicLoad\Rules\ProposalPackageRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Arma un paquete de la propuesta de carrera (tarea 5.6b; reglas-de-negocio.md, «Propuesta de carrera»; confirmado el 16 y
 * 17 de noviembre de 2026): un conjunto de materias de la propuesta, de cualquier semestre (supuesto por confirmar: el
 * usuario dijo «de 1.º y a veces de 2.º»), que se asigna a varios alumnos de nuevo ingreso. La composición es una para toda
 * la carrera. Nace con el código siguiente («P1», «P2»…, el mayor que hay más uno) y con una fila por cada columna de la
 * propuesta (los campus de sus filas, o la columna sin campus, MS5).
 *
 * En una transacción bloquea la propuesta (en borrador o regresada, como UpdateProposalItem) y las partes de los
 * departamentos de sus materias: si alguno ya aceptó la suya, una ValidationException sobre `package`. Sin materias, o con
 * alguna que no tenga fila en la propuesta, una sobre `subjects`. Luego sigue lo sugerido (SyncPackageCounts: la columna sin
 * tocar de un paquete sigue a su sugerido, así que el primer paquete nace con los que cubren el nuevo ingreso esperado
 * y los demás con 0; supuesto por confirmar) y recalcula `package_groups` de sus materias. Deja bitácora de la propuesta y,
 * después del commit, avisa con ProposalPackagesChanged. Quién puede armarlo lo decide CareerProposalPolicy::managePackages
 * antes de llamarla: cualquier coordinador de la carrera en la modalidad (M6), porque la composición es de toda la carrera.
 */
final class CreateProposalPackage
{
    public function __construct(
        private readonly ProposalPackageGuard $guard,
        private readonly ProposalPackageRules $rules,
        private readonly SyncPackageCounts $sync,
        private readonly RecalculatePackageGroups $recalculate,
    ) {}

    /**
     * @param  list<string>  $subjectIds
     *
     * @throws ValidationException
     */
    public function handle(CareerProposal $proposal, array $subjectIds, ?string $name, User $actor): ProposalPackage
    {
        $subjectIds = array_values(array_unique($subjectIds));
        $name = $this->rules->normalizeName($name);

        return DB::transaction(function () use ($proposal, $subjectIds, $name, $actor): ProposalPackage {
            $proposal = $this->guard->lockEditable($proposal->getKey());

            if ($subjectIds === []) {
                throw ValidationException::withMessages(['subjects' => 'Elige al menos una materia para el paquete.']);
            }

            $this->guard->ensureInProposal($proposal, $subjectIds);
            $this->guard->ensureOpen($proposal, $subjectIds);

            $existing = ProposalPackage::query()->where('career_proposal_id', $proposal->getKey())->get(['code', 'position']);
            $number = $existing->map(fn (ProposalPackage $package): int => (int) preg_replace('/\D/', '', $package->code))->max() + 1;

            $package = ProposalPackage::query()->create([
                'career_proposal_id' => $proposal->getKey(),
                'code' => "P{$number}",
                'name' => $name,
                'position' => ($existing->max('position') ?? 0) + 1,
                'created_by' => $actor->getKey(),
            ]);

            $package->subjects()->createMany(array_map(fn (string $subjectId): array => ['subject_id' => $subjectId], $subjectIds));

            $columns = ProposalItem::query()->where('career_proposal_id', $proposal->getKey())->distinct()->pluck('campus_id')->all();
            $package->campuses()->createMany(array_map(fn (?string $campusId): array => ['campus_id' => $campusId, 'count' => 0], $columns));

            $this->sync->handle($proposal, $actor);
            $this->recalculate->handle($proposal, $subjectIds, $actor);

            activity()
                ->performedOn($package)
                ->causedBy($actor)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => ['code' => $package->code, 'name' => $package->name, 'position' => $package->position]])
                ->withProperties(['career_proposal_id' => $proposal->getKey(), 'subject_ids' => $subjectIds])
                ->log("Armó el paquete {$package->code} en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalPackagesChanged::dispatch($proposal->getKey(), $package->getKey());

            return $package->load(['subjects', 'campuses']);
        });
    }
}
