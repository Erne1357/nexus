<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Events\ProposalItemUpdated;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\AcademicLoad\Rules\ProposalPackageGuard;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Rules\SuggestedGroups;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone al día `package_groups` y `requested_groups` de las filas de unas materias (tarea 5.6b; reglas-de-negocio.md, «Propuesta
 * de carrera»): `package_groups` de la fila de una materia en un campus es la suma de lo que abre ese campus de los paquetes de
 * la propuesta que llevan la materia. `requested_groups` sigue siendo el total de la fila, y los paquetes cubren al nuevo
 * ingreso (en primer semestre los esperados de la proyección ya lo incluyen), así que:
 * - una fila corregida (`captured_at`) conserva sus grupos sueltos: `requested_groups = sueltos de antes + package_groups`;
 * - una fila sin tocar sigue sin tocar (`captured_at` nulo, para que siga a la proyección y a los paquetes a la vez) y sus
 *   sueltos son los sugeridos, `ceil(max(0, esperados - nuevo ingreso del campus) / tamaño)` con 35, o 25 con laboratorio
 *   (`demand.target_group_size`), el nuevo ingreso vigente de `proposal_intakes` (supuesto por confirmar); cuando el último
 *   paquete deja de cubrir la materia vuelve a los grupos sugeridos de la proyección, o a los de SuggestedGroups sin ella.
 * No toca la fila de un campus inactivo ni borrado (M14) ni las materias de un departamento que ya aceptó su parte.
 *
 * No abre transacción ni bloquea la propuesta ni las partes: lo hace quien la llama, con las guardas de
 * ProposalPackageGuard. Deja la bitácora de cada fila cambiada y avisa con ProposalItemUpdated después del commit. Si el
 * total pasa del tope de grupos de una fila (ProposalItemRules), una ValidationException sobre `count`. Devuelve cuántas
 * filas cambió.
 */
final class RecalculatePackageGroups
{
    public function __construct(private readonly ProposalPackageGuard $guard) {}

    /**
     * @param  list<string>  $subjectIds
     *
     * @throws ValidationException
     */
    public function handle(CareerProposal $proposal, array $subjectIds, ?User $actor): int
    {
        if ($subjectIds === []) {
            return 0;
        }

        $sums = DB::table('proposal_package_campuses as pc')
            ->join('proposal_packages as p', 'p.id', '=', 'pc.proposal_package_id')
            ->join('proposal_package_subjects as ps', 'ps.proposal_package_id', '=', 'p.id')
            ->where('p.career_proposal_id', $proposal->getKey())
            ->whereIn('ps.subject_id', $subjectIds)
            ->groupBy('ps.subject_id', 'pc.campus_id')
            ->selectRaw('ps.subject_id, pc.campus_id, sum(pc.count)::int as total')
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->subject_id.'|'.($row->campus_id ?? '') => (int) $row->total]);

        $items = ProposalItem::query()
            ->with(['subject' => fn ($query) => $query->withTrashed(), 'campus' => fn ($query) => $query->withTrashed()])
            ->where('career_proposal_id', $proposal->getKey())
            ->whereIn('subject_id', $subjectIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $newStudents = ProposalIntake::query()->where('career_proposal_id', $proposal->getKey())->pluck('new_students', 'campus_id');
        $blocked = $this->guard->acceptedDepartments($proposal, $subjectIds);
        $sizes = SuggestedGroups::fromSettings();
        $changedRows = 0;

        foreach ($items as $item) {
            if ($item->campus_id !== null && ($item->campus === null || ! $item->campus->is_active || $item->campus->trashed())) {
                continue;
            }

            if (in_array($item->subject->department_id, $blocked, true)) {
                continue;
            }

            $packageGroups = $sums->get($item->subject_id.'|'.($item->campus_id ?? ''), 0);
            $untouched = $item->captured_at === null;

            if ($untouched && ($packageGroups > 0 || $item->package_groups > 0)) {
                $needsLab = (bool) $item->subject->needs_lab;
                $requested = $packageGroups > 0
                    ? $packageGroups + $sizes->for(max(0, $item->expected_students - (int) $newStudents->get($item->campus_id ?? '', 0)), $needsLab)
                    : ($item->suggested_groups ?? $sizes->for($item->expected_students, $needsLab));
            } elseif ($packageGroups !== $item->package_groups) {
                $requested = max(0, $item->requested_groups - $item->package_groups) + $packageGroups;
            } else {
                continue;
            }

            if ($packageGroups === $item->package_groups && $requested === $item->requested_groups) {
                continue;
            }

            if ($requested > ProposalItemRules::MAX_REQUESTED_GROUPS) {
                throw ValidationException::withMessages(['count' => "{$item->subject->code} pasaría de ".ProposalItemRules::MAX_REQUESTED_GROUPS.' grupos'.($item->campus === null ? '' : " en {$item->campus->code}").'.']);
            }

            $old = $item->only(['requested_groups', 'package_groups']);
            $item->forceFill(['package_groups' => $packageGroups, 'requested_groups' => $requested])->save();

            $changed = ['requested_groups', 'package_groups'];
            $changedRows++;

            activity()
                ->performedOn($item)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $item->only($changed), 'old' => Arr::only($old, $changed)])
                ->withProperties(['career_proposal_id' => $proposal->getKey(), 'subject_id' => $item->subject_id, 'campus_id' => $item->campus_id])
                ->log("Cambió {$item->subject->code}".($item->campus === null ? '' : " de {$item->campus->code}")." por los paquetes de la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalItemUpdated::dispatch($item, $changed);
        }

        return $changedRows;
    }
}
