<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\ProposalItemUpdated;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Corrige una fila de la propuesta de carrera, que es una materia en un campus (M6): alumnos esperados, grupos
 * solicitados y notas, en los rangos de ProposalItemRules (reglas-de-negocio.md, "Propuesta de carrera"). Los alumnos
 * posibles no se tocan.
 *
 * En una transacción bloquea la propuesta (el estado que vale es el de la base) y exige que esté en borrador o
 * regresada: enviada queda de solo lectura y aceptada ya no cambia; si no, una ValidationException sobre
 * `status`. Luego bloquea la parte del departamento de la materia, en el mismo orden que AcceptProposalReview y
 * ReturnProposalReview (propuesta y luego parte), para que una corrección y la aceptación de esa parte no se
 * crucen (supuesto por confirmar): si el departamento ya la aceptó, también con la propuesta regresada por otro
 * departamento, sus materias quedan bloqueadas (confirmado el 2 de noviembre de 2026) y es una
 * ValidationException sobre `item`. Al final relee la fila con bloqueo para que el valor anterior de la bitácora
 * sea el de la base. Sin cambios no escribe, no registra ni avisa; con cambios guarda, deja en la bitácora de la
 * fila el valor anterior y el nuevo de lo que cambió y, después del commit, avisa con ProposalItemUpdated. Quién
 * puede lo decide CareerProposalPolicy::update sobre la fila antes de llamarla: un coordinador con campus solo corrige las
 * de su campus (M7); quien corrige se recibe como parámetro.
 */
final class UpdateProposalItem
{
    public function __construct(private readonly ProposalItemRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(ProposalItem $item, int $expectedStudents, int $requestedGroups, ?string $notes, User $actor): ProposalItem
    {
        $data = $this->rules->normalize($expectedStudents, $requestedGroups, $notes);

        return DB::transaction(function () use ($item, $data, $actor): ProposalItem {
            $proposal = CareerProposal::query()
                ->with(['career' => fn ($query) => $query->withTrashed(), 'modality' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->findOrFail($item->career_proposal_id);

            if (! $proposal->status->isEditable()) {
                throw ValidationException::withMessages(['status' => $proposal->status === ProposalStatus::Accepted
                    ? 'La propuesta ya se aceptó: ya no cambia.'
                    : 'La propuesta ya se envió: solo se cambia si un departamento la regresa.']);
            }

            $item->load([
                'subject' => fn ($query) => $query->withTrashed()->with(['department' => fn ($department) => $department->withTrashed()]),
                'campus' => fn ($query) => $query->withTrashed(),
            ]);
            $subject = $item->subject;

            $part = ProposalReview::query()
                ->where('career_proposal_id', $proposal->getKey())
                ->where('department_id', $subject->department_id)
                ->lockForUpdate()
                ->first();

            if ($part?->status === ProposalReviewStatus::Accepted) {
                throw ValidationException::withMessages(['item' => "{$subject->department->code} ya aceptó su parte: sus materias ya no cambian."]);
            }

            $item->setRawAttributes($item->newQuery()->lockForUpdate()->findOrFail($item->getKey())->getAttributes(), sync: true);

            $old = $item->only(array_keys($data));
            $item->fill($data);
            $changed = array_keys($item->getDirty());

            if ($changed === []) {
                return $item;
            }

            $item->save();

            activity()
                ->performedOn($item)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $item->only($changed), 'old' => Arr::only($old, $changed)])
                ->withProperties(['career_proposal_id' => $proposal->getKey(), 'subject_id' => $item->subject_id, 'campus_id' => $item->campus_id])
                ->log("Cambió {$subject->code}".($item->campus === null ? '' : " de {$item->campus->code}")." en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalItemUpdated::dispatch($item, $changed);

            return $item;
        });
    }
}
