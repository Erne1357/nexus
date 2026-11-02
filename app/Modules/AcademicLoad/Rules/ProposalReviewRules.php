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
 * (reglas-de-negocio.md, "Propuesta de carrera"; supuestos por confirmar): solo con la propuesta enviada y la
 * parte pendiente, y el comentario de una parte regresada es obligatorio, recortado y de hasta 1000 caracteres.
 * La página puede usar el tope para su propia validación de forma.
 */
final class ProposalReviewRules
{
    public const int MAX_COMMENT_LENGTH = 1000;

    /**
     * Si la parte se puede decidir, con los estados ya leídos con bloqueo; si no, una ValidationException sobre
     * `status`. Con la propuesta regresada, el departamento espera a que el coordinador la reenvíe.
     *
     * @throws ValidationException
     */
    public function ensureDecidable(CareerProposal $proposal, ProposalReview $review, Department $department): void
    {
        $message = match ($proposal->status) {
            ProposalStatus::Draft => 'La propuesta todavía no se envía.',
            ProposalStatus::Returned => 'La propuesta está regresada: se decide cuando el coordinador la reenvíe.',
            ProposalStatus::Accepted => 'La propuesta ya se aceptó.',
            ProposalStatus::Submitted => match ($review->status) {
                ProposalReviewStatus::Accepted => "{$department->code} ya aceptó su parte.",
                ProposalReviewStatus::Returned => "{$department->code} ya regresó su parte.",
                ProposalReviewStatus::Pending => null,
            },
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['status' => $message]);
        }
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
