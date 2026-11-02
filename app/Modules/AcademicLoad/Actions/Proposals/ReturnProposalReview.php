<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\CareerProposalReturned;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ProposalReviewRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El departamento regresa su parte de una propuesta de carrera con comentario, y la propuesta vuelve al
 * coordinador (reglas-de-negocio.md, "Propuesta de carrera"; confirmado el 2 de noviembre de 2026). Regresar no
 * exige los catálogos validados (confirmado) ni toca la carga ni las otras partes.
 *
 * Recorta el comentario y exige que no quede vacío y lleve hasta 1000 caracteres (ProposalReviewRules, supuesto
 * por confirmar), como ValidationException sobre `comment`. En una transacción bloquea la propuesta y luego la
 * parte (el estado que vale es el de la base, como en SubmitCareerProposal) y exige la propuesta enviada y la
 * parte pendiente, sobre `status`. Marca la parte regresada con su comentario, quién y cuándo, y la propuesta
 * regresada. Deja bitácora de la parte y, después del commit, avisa con CareerProposalReturned. Quién puede lo
 * decide ProposalReviewPolicy::decide antes de llamarla; quien regresa se recibe como parámetro.
 */
final class ReturnProposalReview
{
    public function __construct(private readonly ProposalReviewRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(ProposalReview $review, string $comment, User $actor): ProposalReview
    {
        $comment = $this->rules->normalizeComment($comment);

        return DB::transaction(function () use ($review, $comment, $actor): ProposalReview {
            $proposal = CareerProposal::query()
                ->with(['term', 'campus' => fn ($query) => $query->withTrashed(), 'career' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->findOrFail($review->career_proposal_id);

            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);
            $review->load(['department' => fn ($query) => $query->withTrashed()]);
            $department = $review->department;

            $this->rules->ensureDecidable($proposal, $review, $department);

            $review->update(['status' => ProposalReviewStatus::Returned, 'comment' => $comment, 'decided_by' => $actor->getKey(), 'decided_at' => now()]);

            $from = $proposal->status;
            $proposal->update(['status' => ProposalStatus::Returned]);

            activity()
                ->performedOn($review)
                ->causedBy($actor)
                ->event('returned')
                ->withChanges([
                    'old' => ['status' => ProposalReviewStatus::Pending->value, 'comment' => null],
                    'attributes' => ['status' => ProposalReviewStatus::Returned->value, 'comment' => $comment],
                ])
                ->withProperties([
                    'career_proposal_id' => $proposal->getKey(),
                    'proposal_status' => ['old' => $from->value, 'attributes' => $proposal->status->value],
                ])
                ->log("Regresó la parte de {$department->code} en la propuesta de {$proposal->career->code} en {$proposal->campus->code} para el periodo {$proposal->term->code}");

            CareerProposalReturned::dispatch($proposal, $department, $comment);

            return $review;
        });
    }
}
