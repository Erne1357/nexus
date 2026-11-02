<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Actions\Transitions\ProposeLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\ProposalReviewAccepted;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\Constraints\CatalogsValidated;
use App\Modules\AcademicLoad\Rules\ProposalReviewRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * El departamento acepta su parte de una propuesta de carrera: todo lo que esa carrera le pide
 * (reglas-de-negocio.md, "Propuesta de carrera"; confirmado el 2 de noviembre de 2026). Lo aceptado ya no cambia.
 *
 * En una transacción bloquea la propuesta y luego la parte (el estado que vale es el de la base, como en
 * SubmitCareerProposal y UpdateProposalItem) y exige la parte pendiente con la propuesta enviada o regresada por
 * otro departamento (ProposalReviewRules; confirmado el 2 de noviembre de 2026). Exige HC-14, los catálogos del
 * departamento validados en el periodo y el campus de la propuesta (CatalogsValidated, con bloqueo compartido),
 * como ValidationException sobre `catalogs`; y la carga del departamento en ese periodo y campus, que bloquea,
 * sobre `load` (supuesto por confirmar: volver a abrir el periodo en el campus la crea). Marca la parte aceptada
 * con quién y cuándo: desde ahí el coordinador ya no cambia sus materias, aunque la propuesta siga regresada. Si la
 * propuesta está enviada y ya no queda ninguna parte sin aceptar, queda aceptada; regresada, sigue regresada,
 * porque tiene una parte regresada. Si la carga sigue en borrador, la propone con ProposeLoad en la misma transacción
 * (load_transitions y bitácora de la carga); si ProposeLoad la bloquea, no se acepta nada. Deja bitácora de la
 * parte y, después del commit, avisa con ProposalReviewAccepted. Quién puede lo decide ProposalReviewPolicy::decide
 * antes de llamarla; quien acepta se recibe como parámetro.
 */
final class AcceptProposalReview
{
    public function __construct(
        private readonly ProposalReviewRules $rules,
        private readonly CatalogsValidated $catalogsValidated,
        private readonly ProposeLoad $proposeLoad,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(ProposalReview $review, User $actor): ProposalReview
    {
        try {
            return $this->accept($review, $actor);
        } catch (Throwable $e) {
            $review->refresh();

            throw $e;
        }
    }

    private function accept(ProposalReview $review, User $actor): ProposalReview
    {
        return DB::transaction(function () use ($review, $actor): ProposalReview {
            $proposal = CareerProposal::query()
                ->with(['term', 'campus' => fn ($query) => $query->withTrashed(), 'career' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->findOrFail($review->career_proposal_id);

            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);
            $review->load(['department' => fn ($query) => $query->withTrashed()]);
            $department = $review->department;

            $this->rules->ensureDecidable($proposal, $review, $department);

            $reasons = $this->catalogsValidated->reasonsFor($proposal->term_id, $proposal->campus, $review->department_id);

            if ($reasons !== []) {
                throw ValidationException::withMessages(['catalogs' => $reasons]);
            }

            $load = AcademicLoad::query()
                ->where('term_id', $proposal->term_id)
                ->where('campus_id', $proposal->campus_id)
                ->where('department_id', $review->department_id)
                ->lockForUpdate()
                ->first();

            if ($load === null) {
                throw ValidationException::withMessages(['load' => "{$department->code} no tiene carga en {$proposal->campus->code} para el periodo {$proposal->term->code}: vuelve a abrir el periodo en el campus para crearla."]);
            }

            $review->update(['status' => ProposalReviewStatus::Accepted, 'decided_by' => $actor->getKey(), 'decided_at' => now()]);

            $from = $proposal->status;
            $proposalAccepted = $proposal->status === ProposalStatus::Submitted
                && ! $proposal->reviews()->where('status', '<>', ProposalReviewStatus::Accepted)->exists();

            if ($proposalAccepted) {
                $proposal->update(['status' => ProposalStatus::Accepted]);
            }

            activity()
                ->performedOn($review)
                ->causedBy($actor)
                ->event('accepted')
                ->withChanges(['old' => ['status' => ProposalReviewStatus::Pending->value], 'attributes' => ['status' => ProposalReviewStatus::Accepted->value]])
                ->withProperties([
                    'career_proposal_id' => $proposal->getKey(),
                    'proposal_status' => ['old' => $from->value, 'attributes' => $proposal->status->value],
                ])
                ->log("Aceptó la parte de {$department->code} en la propuesta de {$proposal->career->code} en {$proposal->campus->code} para el periodo {$proposal->term->code}");

            if ($load->status === LoadStatus::Draft) {
                $this->proposeLoad->handle($load, $actor, "Aceptó su parte de la propuesta de {$proposal->career->code}.");
            }

            ProposalReviewAccepted::dispatch($review, $proposalAccepted);

            return $review;
        });
    }
}
