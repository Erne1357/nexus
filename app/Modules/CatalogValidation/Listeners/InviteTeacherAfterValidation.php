<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Jobs\InviteReviewTeachersJob;
use App\Modules\CatalogValidation\Queries\TeacherInvitationReleaseQuery;
use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\TeacherAccessChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Models\Teacher;

/**
 * Un docente que queda en condiciones de recibir su invitación después de que su departamento validó la revisión de
 * docentes la recibe en ese momento (AC-09): al capturarle el correo institucional (supuesto por confirmar), y también
 * al reactivarlo, al adscribirlo a ese departamento o cuando SyncTeacherAccess le crea o le liga el usuario (supuesto
 * por confirmar). Encola InviteReviewTeachersJob solo para ese docente, que lo invita si sigue sin acceso ni invitación
 * vigente, a nombre de quien hizo el cambio o, sin él, de quien validó.
 *
 * Cuenta la revisión que libera su invitación (TeacherInvitationReleaseQuery): una de docentes validada de cualquiera de
 * sus departamentos en un periodo que no ha terminado, la primera que se validó. Sale después del commit de Faculty.
 */
final class InviteTeacherAfterValidation
{
    public function __construct(private readonly TeacherInvitationReleaseQuery $releases) {}

    public function handle(TeacherUpdated|AdscriptionChanged|TeacherAccessChanged $event): void
    {
        $teacher = match (true) {
            $event instanceof TeacherUpdated => array_intersect(['institutional_email', 'is_active'], $event->changed) === [] ? null : $event->teacher,
            $event instanceof AdscriptionChanged => $event->adscription->trashed() ? null : $event->adscription->teacher,
            default => in_array($event->result, [AccessSync::Created, AccessSync::Linked], true) ? $event->teacher : null,
        };

        if (! $teacher instanceof Teacher || ! $teacher->is_active || $teacher->institutional_email === null) {
            return;
        }

        $review = $this->releases->releasingReview($teacher);

        if ($review !== null) {
            InviteReviewTeachersJob::dispatch($review->getKey(), $teacher->getKey(), $event instanceof TeacherAccessChanged ? null : $event->actor?->getKey());
        }
    }
}
