<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use Illuminate\Validation\ValidationException;

/**
 * Las guardas que comparten las Actions de paquetes (tarea 5.6b), con las mismas de UpdateProposalItem y en su mismo orden
 * de bloqueo (la propuesta y luego las partes): la propuesta está en borrador o regresada y el departamento de cada materia
 * afectada no ha aceptado su parte. Leen con bloqueo, así que van dentro de la transacción de quien las llama.
 */
final class ProposalPackageGuard
{
    /**
     * Bloquea la propuesta, con su carrera y modalidad, y exige que se pueda editar: enviada queda de solo lectura y
     * aceptada ya no cambia.
     *
     * @throws ValidationException sobre `status`
     */
    public function lockEditable(string $proposalId): CareerProposal
    {
        $proposal = CareerProposal::query()
            ->with(['career' => fn ($query) => $query->withTrashed(), 'modality' => fn ($query) => $query->withTrashed()])
            ->lockForUpdate()
            ->findOrFail($proposalId);

        if (! $proposal->status->isEditable()) {
            throw ValidationException::withMessages(['status' => $proposal->status === ProposalStatus::Accepted
                ? 'La propuesta ya se aceptó: ya no cambia.'
                : 'La propuesta ya se envió: solo se cambia si un departamento la regresa.']);
        }

        return $proposal;
    }

    /**
     * Cada materia tiene fila en la propuesta (los paquetes solo llevan materias que se pueden pedir).
     *
     * @param  list<string>  $subjectIds
     *
     * @throws ValidationException sobre `subjects`
     */
    public function ensureInProposal(CareerProposal $proposal, array $subjectIds): void
    {
        $found = ProposalItem::query()
            ->where('career_proposal_id', $proposal->getKey())
            ->whereIn('subject_id', $subjectIds)
            ->distinct()
            ->pluck('subject_id')
            ->all();

        $missing = array_values(array_diff($subjectIds, $found));

        if ($missing !== []) {
            $codes = Subject::withTrashed()->whereKey($missing)->orderBy('code')->pluck('code')->implode(', ');

            throw ValidationException::withMessages(['subjects' => "No están en la propuesta: {$codes}."]);
        }
    }

    /**
     * Los departamentos de las materias que ya aceptaron su parte (también con la propuesta regresada por otro), por
     * clave. Bloquea sus partes.
     *
     * @param  list<string>  $subjectIds
     * @return list<string> ids de departamento
     */
    public function acceptedDepartments(CareerProposal $proposal, array $subjectIds): array
    {
        $departments = Subject::withTrashed()->whereKey($subjectIds)->distinct()->pluck('department_id')->all();

        return ProposalReview::query()
            ->where('career_proposal_id', $proposal->getKey())
            ->whereIn('department_id', $departments)
            ->where('status', ProposalReviewStatus::Accepted)
            ->lockForUpdate()
            ->pluck('department_id')
            ->all();
    }

    /**
     * Ninguna de las materias es de un departamento que ya aceptó su parte (confirmado el 2 de noviembre de 2026).
     *
     * @param  list<string>  $subjectIds
     *
     * @throws ValidationException sobre `package`
     */
    public function ensureOpen(CareerProposal $proposal, array $subjectIds): void
    {
        $accepted = $this->acceptedDepartments($proposal, $subjectIds);

        if ($accepted === []) {
            return;
        }

        $subject = Subject::withTrashed()->with(['department' => fn ($query) => $query->withTrashed()])
            ->whereKey($subjectIds)
            ->whereIn('department_id', $accepted)
            ->orderBy('code')
            ->first();

        throw ValidationException::withMessages(['package' => "{$subject->department->code} ya aceptó su parte: sus materias ya no cambian."]);
    }
}
