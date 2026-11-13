<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\CareerProposalSubmitted;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ProposalAvailability;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Envía, o reenvía después de que un departamento la regresó, la propuesta de carrera a sus departamentos
 * (reglas-de-negocio.md, "Propuesta de carrera"). Es una por carrera y modalidad y se envía una vez, con las filas de
 * todos sus campus; la envía o la reenvía cualquiera de los coordinadores de la carrera en la modalidad (M6). Cada
 * departamento de una materia con grupos, en cualquier campus, tiene su parte,
 * que decide completa; la propuesta queda aceptada cuando todas sus partes lo están (confirmado el 2 de
 * noviembre de 2026). La decisión de cada departamento es de la bandeja (AcceptProposalReview y
 * ReturnProposalReview), que bloquea la propuesta (`lockForUpdate`) antes de cambiar una parte, como esta Action.
 *
 * En una transacción bloquea la propuesta (el estado que vale es el de la base) y exige que esté en borrador o
 * regresada; si no, una ValidationException sobre `status`. Exige al menos una materia con grupos, sobre
 * `requested_groups`. Con las partes bloqueadas (supuestos por confirmar):
 *
 * - la aceptada se queda como está: lo que ya aceptaron no cambia;
 * - las demás vuelven a pendiente, sin comentario ni decisión; lo que tenían queda en la bitácora;
 * - un departamento nuevo recibe su parte pendiente;
 * - la de un departamento al que ya no se le piden grupos se borra (no es catálogo; queda en la bitácora).
 *
 * Vuelve a exigir lo de armarla (ProposalAvailability): modalidad activa, periodo abierto en ella y carrera ofrecida en
 * algún campus, sobre `term` o `career` (supuesto por confirmar); y que cada campus donde se piden grupos siga activo y
 * ofrezca la carrera (HC-13 por columna), sobre `career`. Queda enviada, o aceptada si todas las partes que quedan ya estaban aceptadas, con quién y cuándo se envió.
 * Deja bitácora de la propuesta y, después del commit, avisa con CareerProposalSubmitted y los departamentos
 * pendientes; quien la envió queda en `submitted_by`, para avisar a los demás coordinadores (MS18). Con catálogos sin validar de un departamento involucrado no se bloquea: el aviso lo muestra la
 * página (CareerProposalQuery). Quién puede enviar lo decide CareerProposalPolicy::submit antes de llamarla;
 * quien envía se recibe como parámetro.
 */
final class SubmitCareerProposal
{
    public function __construct(private readonly ProposalAvailability $availability) {}

    /**
     * @throws ValidationException
     */
    public function handle(CareerProposal $proposal, User $actor): CareerProposal
    {
        return DB::transaction(function () use ($proposal, $actor): CareerProposal {
            $proposal->setRawAttributes($proposal->newQuery()->lockForUpdate()->findOrFail($proposal->getKey())->getAttributes(), sync: true);
            $from = $proposal->status;

            if (! $from->isEditable()) {
                throw ValidationException::withMessages(['status' => $from === ProposalStatus::Accepted ? 'La propuesta ya se aceptó.' : 'La propuesta ya se envió.']);
            }

            $proposal->load([
                'term',
                'modality' => fn ($query) => $query->withTrashed(),
                'career' => fn ($query) => $query->withTrashed(),
            ]);
            $this->availability->ensure($proposal->term, $proposal->modality, $proposal->career);

            $involved = $this->involvedDepartments($proposal);

            if ($involved === []) {
                throw ValidationException::withMessages(['requested_groups' => 'Pide al menos un grupo antes de enviar la propuesta.']);
            }

            $this->availability->ensureRequestedColumns($proposal->career, ProposalItem::query()
                ->requested()
                ->where('career_proposal_id', $proposal->getKey())
                ->whereNotNull('campus_id')
                ->distinct()
                ->pluck('campus_id')
                ->all());

            $reviews = $proposal->reviews()
                ->with(['department' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (ProposalReview $review): string => $review->department->code)
                ->keyBy('department_id');

            $removed = $reviews->reject(fn (ProposalReview $review): bool => in_array($review->department_id, $involved, true));

            if ($removed->isNotEmpty()) {
                ProposalReview::query()->whereKey($removed->modelKeys())->delete();
            }

            $pending = [];
            $accepted = [];
            $reset = [];

            foreach ($involved as $departmentId) {
                $review = $reviews->get($departmentId);

                if ($review?->status === ProposalReviewStatus::Accepted) {
                    $accepted[] = $departmentId;

                    continue;
                }

                if ($review === null) {
                    $proposal->reviews()->create(['department_id' => $departmentId, 'status' => ProposalReviewStatus::Pending]);
                } elseif ($review->status !== ProposalReviewStatus::Pending || $review->comment !== null || $review->decided_by !== null) {
                    $reset[] = $this->logged($review);
                    $review->update(['status' => ProposalReviewStatus::Pending, 'comment' => null, 'decided_by' => null, 'decided_at' => null]);
                }

                $pending[] = $departmentId;
            }

            $to = $pending === [] ? ProposalStatus::Accepted : ProposalStatus::Submitted;
            $proposal->update(['status' => $to, 'submitted_by' => $actor->getKey(), 'submitted_at' => now()]);
            activity()
                ->performedOn($proposal)
                ->causedBy($actor)
                ->event('submitted')
                ->withChanges(['old' => ['status' => $from->value], 'attributes' => ['status' => $to->value]])
                ->withProperties([
                    'pending_department_ids' => $pending,
                    'accepted_department_ids' => $accepted,
                    'reset_reviews' => $reset,
                    'removed_reviews' => $removed->map($this->logged(...))->values()->all(),
                ])
                ->log(($from === ProposalStatus::Returned ? 'Reenvió' : 'Envió')
                    ." la propuesta de {$proposal->career->code} en {$proposal->modality->name} para el periodo {$proposal->term->code}");

            CareerProposalSubmitted::dispatch($proposal, $pending, $from === ProposalStatus::Returned);

            return $proposal;
        });
    }

    /**
     * Los departamentos de las materias con grupos, por clave.
     *
     * @return list<string>
     */
    private function involvedDepartments(CareerProposal $proposal): array
    {
        return ProposalItem::query()
            ->requested()
            ->where('proposal_items.career_proposal_id', $proposal->getKey())
            ->join('subjects', 'subjects.id', '=', 'proposal_items.subject_id')
            ->join('departments', 'departments.id', '=', 'subjects.department_id')
            ->distinct()
            ->orderBy('departments.code')
            ->toBase()
            ->get(['subjects.department_id', 'departments.code'])
            ->pluck('department_id')
            ->all();
    }

    /**
     * Lo que tenía una parte antes de reiniciarla o borrarla, para la bitácora.
     *
     * @return array{department_id: string, status: string, comment: ?string}
     */
    private function logged(ProposalReview $review): array
    {
        return ['department_id' => $review->department_id, 'status' => $review->status->value, 'comment' => $review->comment];
    }
}
