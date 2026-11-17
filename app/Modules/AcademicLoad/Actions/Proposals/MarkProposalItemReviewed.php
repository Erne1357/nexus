<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Events\ProposalItemReviewed;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * «Está bien» o «Dejar» en «Revisar proyección» (tarea 5.6, opción C elegida por el usuario el 17 de noviembre de 2026):
 * marca revisada una fila de la propuesta (`reviewed_at`, `reviewed_by`) sin tocar sus cifras, para que deje de pedir
 * revisión hasta que cambie lo revisado (supuesto por confirmar). No pide motivo: la diferencia solo se resalta.
 *
 * Tiene las guardas, la transacción y el bloqueo de UpdateProposalItem::change (propuesta en borrador o regresada y parte
 * del departamento sin aceptar). Una fila ya revisada no se escribe otra vez. Con la marca nueva deja una entrada de
 * bitácora en la fila y, después del commit, avisa con ProposalItemReviewed. Quién puede lo decide CareerProposalPolicy::update
 * sobre la fila antes de llamarla (M7).
 */
final class MarkProposalItemReviewed
{
    public function __construct(private readonly UpdateProposalItem $update) {}

    /**
     * @throws ValidationException
     */
    public function handle(ProposalItem $item, User $actor): ProposalItem
    {
        return DB::transaction(function () use ($item, $actor): ProposalItem {
            $marked = false;

            $this->update->change($item, $actor, function (ProposalItem $locked) use ($actor, &$marked): array {
                $marked = $locked->reviewed_at === null;

                return $marked ? ['reviewed_at' => now(), 'reviewed_by' => $actor->getKey()] : [];
            });

            if (! $marked) {
                return $item;
            }

            $proposal = $item->proposal()->with(['career' => fn ($query) => $query->withTrashed(), 'modality' => fn ($query) => $query->withTrashed()])->firstOrFail();

            activity()
                ->performedOn($item)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $item->only(['reviewed_at', 'reviewed_by']), 'old' => ['reviewed_at' => null, 'reviewed_by' => null]])
                ->withProperties(['career_proposal_id' => $proposal->getKey(), 'subject_id' => $item->subject_id, 'campus_id' => $item->campus_id])
                ->log("Revisó la proyección de {$item->subject->code}".($item->campus === null ? '' : " de {$item->campus->code}")." en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalItemReviewed::dispatch($item);

            return $item;
        });
    }
}
