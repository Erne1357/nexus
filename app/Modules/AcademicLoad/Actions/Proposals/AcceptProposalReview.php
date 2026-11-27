<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Transitions\ProposeLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\ProposalReviewAccepted;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\Constraints\CatalogsValidated;
use App\Modules\AcademicLoad\Rules\ProposalReviewRules;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\SelfApproval;
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
 * departamento validados en el periodo y la modalidad de la propuesta (CatalogsValidated, con bloqueo compartido),
 * como ValidationException sobre `catalogs`; y la carga del departamento en ese periodo y modalidad (M1), que bloquea,
 * sobre `load` (supuesto por confirmar: completar la apertura del periodo en la modalidad la crea, M4); y HC-13 por
 * columna: cada campus donde la parte pide grupos sigue activo, sobre `campus` (ensureActiveColumns()). Marca la parte
 * aceptada con quién y cuándo: desde ahí el coordinador ya no cambia sus materias, aunque la propuesta siga regresada.
 * Si la propuesta está enviada y ya no queda ninguna parte sin aceptar, queda aceptada; regresada, sigue regresada,
 * porque tiene una parte regresada. Si la carga sigue en borrador, la propone con ProposeLoad en la misma transacción
 * (load_transitions y bitácora de la carga); si ProposeLoad la bloquea, no se acepta nada. Deja bitácora de la
 * parte y, después del commit, avisa con ProposalReviewAccepted. Quién puede lo decide ProposalReviewPolicy::decide
 * antes de llamarla; quien acepta se recibe como parámetro.
 *
 * AZ-01: si quien acepta es quien envió la propuesta (`submitted_by`, la última vez), procede y la entrada de la
 * bitácora lleva `self_approval: true` (Core\Rules\SelfApproval).
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
                ->with(['term', 'modality' => fn ($query) => $query->withTrashed(), 'career' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->findOrFail($review->career_proposal_id);

            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);
            $review->load(['department' => fn ($query) => $query->withTrashed()]);
            $department = $review->department;

            $this->rules->ensureDecidable($proposal, $review, $department);

            $reasons = $this->catalogsValidated->reasonsFor($proposal->term_id, $proposal->modality, $review->department_id);

            if ($reasons !== []) {
                throw ValidationException::withMessages(['catalogs' => $reasons]);
            }

            $load = AcademicLoad::query()
                ->ofDepartment($proposal->term_id, $proposal->modality_id, $review->department_id)
                ->lockForUpdate()
                ->first();

            if ($load === null) {
                throw ValidationException::withMessages(['load' => "{$department->code} no tiene carga en {$proposal->modality->name} para el periodo {$proposal->term->code}: completa la apertura del periodo en {$proposal->modality->name} para crearla."]);
            }

            $this->ensureActiveColumns($proposal, $review);

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
                    ...SelfApproval::mark($actor, $proposal->submitted_by),
                ])
                ->log("Aceptó la parte de {$department->code} en la propuesta de {$proposal->career->code} en {$proposal->modality->name} para el periodo {$proposal->term->code}");

            if ($load->status === LoadStatus::Draft) {
                $this->proposeLoad->handle($load, $actor, "Aceptó su parte de la propuesta de {$proposal->career->code}.");
            }

            ProposalReviewAccepted::dispatch($review, $proposalAccepted);

            return $review;
        });
    }

    /**
     * HC-13 por columna, otra vez al aceptar: cada campus donde la parte del departamento pide grupos sigue activo, porque
     * aceptar crea esos grupos en su campus (M8) y un campus pudo desactivarse después del envío. Si no, se rechaza con el
     * primer campus por clave; la parte se regresa y el coordinador deja en cero sus grupos de ese campus (M14).
     *
     * @throws ValidationException sobre `campus`
     */
    private function ensureActiveColumns(CareerProposal $proposal, ProposalReview $review): void
    {
        $inactive = Campus::withTrashed()
            ->whereIn('id', ProposalItem::query()
                ->requested()
                ->where('career_proposal_id', $proposal->getKey())
                ->whereIn('subject_id', Subject::query()->withTrashed()->where('department_id', $review->department_id)->select('id'))
                ->select('campus_id'))
            ->where(fn ($query) => $query->where('is_active', false)->orWhereNotNull('deleted_at'))
            ->orderBy('code')
            ->value('code');

        if ($inactive !== null) {
            throw ValidationException::withMessages(['campus' => "{$inactive} está inactivo y la parte pide grupos ahí: regrésala para que el coordinador deje en cero sus grupos de {$inactive} (HC-13)."]);
        }
    }
}
