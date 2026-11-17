<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;
use App\Modules\AcademicLoad\Rules\ProposalPackageGuard;
use App\Modules\AcademicLoad\Rules\ProposalPackageRules;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Rules\DemandSettings;

/**
 * Calcula los paquetes sugeridos de cada columna y hace que sigan a ellos los que el coordinador no ha tocado (tarea 5.6b;
 * reglas-de-negocio.md, «Propuesta de carrera»). Los sugeridos son `ceil(nuevo ingreso vigente / tamaño objetivo normal)`
 * (DemandSettings) en el primer paquete (el de menor posición) y 0 en los demás, con el nuevo ingreso vigente de
 * `proposal_intakes` de esa columna (supuesto por confirmar); sin nuevo ingreso en la columna no hay sugerido. Una cuenta con
 * `captured_at` se conserva. Un paquete con alguna materia de un departamento que ya aceptó su parte no se mueve solo
 * (esas materias ya no cambian), pero sí guarda su sugerido. Después recalcula las filas de las materias de todos los
 * paquetes (RecalculatePackageGroups), porque los grupos sueltos sugeridos de una fila sin tocar dependen del nuevo ingreso.
 *
 * No abre transacción ni bloquea la propuesta: lo hace quien la llama, con la propuesta ya bloqueada. Devuelve cuántas
 * cuentas se movieron.
 */
final class SyncPackageCounts
{
    public function __construct(
        private readonly RecalculatePackageGroups $recalculate,
        private readonly ProposalPackageGuard $guard,
        private readonly ProposalPackageRules $rules,
    ) {}

    public function handle(CareerProposal $proposal, ?User $actor): int
    {
        $packages = ProposalPackage::query()
            ->with(['subjects', 'campuses'])
            ->where('career_proposal_id', $proposal->getKey())
            ->orderBy('position')
            ->orderBy('code')
            ->lockForUpdate()
            ->get();

        if ($packages->isEmpty()) {
            return 0;
        }

        $intakes = ProposalIntake::query()
            ->where('career_proposal_id', $proposal->getKey())
            ->get()
            ->keyBy(fn (ProposalIntake $intake): string => $intake->campus_id ?? '');

        $size = DemandSettings::fromSettings()->groupSizes->defaultSize;
        $first = $packages->first();
        $moved = 0;
        $affected = $packages->flatMap(fn (ProposalPackage $package) => $package->subjects->pluck('subject_id'))->unique()->values()->all();

        foreach ($packages as $package) {
            $subjectIds = $package->subjects->pluck('subject_id')->all();
            $blocked = $this->guard->acceptedDepartments($proposal, $subjectIds) !== [];

            foreach ($package->campuses as $column) {
                /** @var ProposalPackageCampus $column */
                $intake = $intakes->get($column->campus_id ?? '');
                $suggested = $intake === null ? null : ($package->is($first) ? $this->rules->suggestedPackages($intake->new_students, $size) : 0);

                if ($column->suggested_count !== $suggested) {
                    $column->suggested_count = $suggested;
                }

                if ($column->captured_at === null && $suggested !== null && ! $blocked && $column->count !== $suggested) {
                    $column->count = $suggested;
                    $moved++;
                }

                if ($column->isDirty()) {
                    $column->save();
                }
            }
        }

        $this->recalculate->handle($proposal, $affected, $actor);

        return $moved;
    }
}
