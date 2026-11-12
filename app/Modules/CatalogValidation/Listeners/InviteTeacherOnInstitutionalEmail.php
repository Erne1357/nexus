<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Jobs\InviteReviewTeachersJob;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Events\TeacherUpdated;

/**
 * Si el jefe captura el correo institucional de un docente cuando su departamento ya validó la revisión de docentes,
 * la invitación sale en ese momento (AC-09, supuesto por confirmar): encola InviteReviewTeachersJob solo para ese
 * docente, que lo invita si sigue sin acceso ni invitación vigente. El periodo es el activo de quien capturó (el que
 * usa la validación), o el actual si no hubo sesión. Basta con uno de sus departamentos validado. Sale después del
 * commit de UpdateTeacher.
 */
final class InviteTeacherOnInstitutionalEmail
{
    public function __construct(private readonly Actor $context) {}

    public function handle(TeacherUpdated $event): void
    {
        $teacher = $event->teacher;

        if (! in_array('institutional_email', $event->changed, true) || $teacher->institutional_email === null) {
            return;
        }

        $term = $event->actor === null ? Term::current()->first() : $this->context->forUser($event->actor)->activeTerm();

        if ($term === null) {
            return;
        }

        $review = CatalogReview::query()
            ->where('term_id', $term->getKey())
            ->where('catalog', Catalog::Teachers)
            ->where('status', ReviewStatus::Validated)
            ->whereIn('department_id', $teacher->adscriptions()->select('department_id'))
            ->orderBy('validated_at')
            ->first();

        if ($review !== null) {
            InviteReviewTeachersJob::dispatch($review->getKey(), $teacher->getKey());
        }
    }
}
