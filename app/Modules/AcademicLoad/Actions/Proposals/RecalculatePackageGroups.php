<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Events\ProposalItemUpdated;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone al día `package_groups` de las filas de unas materias (tarea 5.6b; reglas-de-negocio.md, «Propuesta de carrera»):
 * para la fila de una materia en un campus, la suma de lo que abre ese campus de los paquetes de la propuesta que llevan la
 * materia. `requested_groups` sigue siendo el total de la fila: los grupos sueltos que ya tenía (`requested_groups` menos el
 * `package_groups` de antes) se conservan, así que `requested_groups = sueltos + package_groups nuevo`. La fila cambiada queda
 * marcada como corregida (`captured_at`, `captured_by`): el coordinador ya decidió, y la proyección ya no la mueve sola
 * (supuesto por confirmar). No toca la fila de un campus inactivo ni borrado (M14).
 *
 * No abre transacción ni bloquea la propuesta ni las partes: lo hace quien la llama, con las guardas de
 * ProposalPackageGuard. Deja la bitácora de cada fila cambiada y avisa con ProposalItemUpdated después del commit. Si el
 * total pasa del tope de grupos de una fila (ProposalItemRules), una ValidationException sobre `count`. Devuelve cuántas
 * filas cambió.
 */
final class RecalculatePackageGroups
{
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

        $changedRows = 0;

        foreach ($items as $item) {
            if ($item->campus_id !== null && ($item->campus === null || ! $item->campus->is_active || $item->campus->trashed())) {
                continue;
            }

            $packageGroups = $sums->get($item->subject_id.'|'.($item->campus_id ?? ''), 0);

            if ($packageGroups === $item->package_groups) {
                continue;
            }

            $requested = max(0, $item->requested_groups - $item->package_groups) + $packageGroups;

            if ($requested > ProposalItemRules::MAX_REQUESTED_GROUPS) {
                throw ValidationException::withMessages(['count' => "{$item->subject->code} pasaría de ".ProposalItemRules::MAX_REQUESTED_GROUPS.' grupos'.($item->campus === null ? '' : " en {$item->campus->code}").'.']);
            }

            $old = $item->only(['requested_groups', 'package_groups']);
            $item->forceFill([
                'package_groups' => $packageGroups,
                'requested_groups' => $requested,
                'captured_at' => now(),
                'captured_by' => $actor?->getKey(),
            ])->save();

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
