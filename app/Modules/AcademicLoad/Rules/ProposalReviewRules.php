<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use Illuminate\Validation\ValidationException;

/**
 * Cuándo decide un departamento su parte de una propuesta de carrera y qué lleva al regresarla
 * (reglas-de-negocio.md, "Propuesta de carrera"): la parte pendiente se decide con la propuesta enviada y también
 * con la propuesta regresada por otro departamento, mientras el coordinador la corrige; lo que acepta entonces
 * queda congelado para el coordinador (confirmado el 2 de noviembre de 2026). El comentario de una parte regresada
 * es obligatorio, recortado y de hasta 1000 caracteres (supuesto por confirmar). La página puede usar el tope
 * para su propia validación de forma, y la bandeja, isDecidable() para ofrecer los botones.
 */
final class ProposalReviewRules
{
    public const int MAX_COMMENT_LENGTH = 1000;

    /**
     * Si una parte con ese estado se puede decidir en una propuesta con ese estado: pendiente, con la propuesta
     * enviada o regresada. En borrador todavía no se envía y aceptada ya no cambia.
     */
    public function isDecidable(ProposalStatus $proposal, ProposalReviewStatus $review): bool
    {
        return $review === ProposalReviewStatus::Pending
            && ($proposal === ProposalStatus::Submitted || $proposal === ProposalStatus::Returned);
    }

    /**
     * Si la parte se puede decidir (isDecidable), con los estados ya leídos con bloqueo; si no, una
     * ValidationException sobre `status` con el motivo.
     *
     * @throws ValidationException
     */
    public function ensureDecidable(CareerProposal $proposal, ProposalReview $review, Department $department): void
    {
        if ($this->isDecidable($proposal->status, $review->status)) {
            return;
        }

        throw ValidationException::withMessages(['status' => match (true) {
            $proposal->status === ProposalStatus::Draft => 'La propuesta todavía no se envía.',
            $proposal->status === ProposalStatus::Accepted => 'La propuesta ya se aceptó.',
            $review->status === ProposalReviewStatus::Accepted => "{$department->code} ya aceptó su parte.",
            default => "{$department->code} ya regresó su parte.",
        }]);
    }

    /**
     * El comentario recortado, o una ValidationException sobre `comment` si queda vacío o es muy largo.
     *
     * @throws ValidationException
     */
    public function normalizeComment(string $comment): string
    {
        $comment = trim($comment);

        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'Escribe por qué regresas la propuesta.']);
        }

        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw ValidationException::withMessages(['comment' => 'El comentario lleva hasta '.self::MAX_COMMENT_LENGTH.' caracteres.']);
        }

        return $comment;
    }
}
