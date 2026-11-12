<?php

namespace App\Modules\CatalogValidation\Jobs;

use App\Modules\CatalogValidation\Actions\InviteReviewTeachers;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Las invitaciones de acceso de una revisión de docentes validada (InviteReviewTeachers, AC-09): un departamento puede
 * tener decenas de docentes, así que va a la cola (CLAUDE.md, §4.7). Lleva solo ids: la revisión, el docente si es
 * uno solo y quien hizo el cambio, si no fue quien validó. Si la revisión ya no existe o ya no está validada, no hace
 * nada.
 */
final class InviteReviewTeachersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $reviewId,
        public readonly ?string $teacherId = null,
        public readonly ?string $actorId = null,
    ) {}

    public function handle(InviteReviewTeachers $invite): void
    {
        $review = CatalogReview::query()->find($this->reviewId);

        if ($review === null || $review->status !== ReviewStatus::Validated) {
            return;
        }

        $invite->handle($review, $this->teacherId, $this->actorId === null ? null : User::query()->find($this->actorId));
    }
}
