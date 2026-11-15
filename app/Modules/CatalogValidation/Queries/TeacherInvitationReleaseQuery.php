<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\InvitationRelease;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué validación libera la invitación de acceso de un docente (AC-09): una revisión de docentes validada de
 * cualquiera de sus departamentos (adscripciones sin borrar) en un periodo que no ha terminado, el actual o uno que se
 * prepara (supuesto por confirmar); la primera que se validó. La usan InviteTeacherAfterValidation, para invitar al
 * que queda en condiciones después, y Faculty por InvitationRelease, para saber si «Enviar invitación» ya se puede.
 */
final class TeacherInvitationReleaseQuery implements InvitationRelease
{
    public function releasingReview(Teacher $teacher): ?CatalogReview
    {
        return CatalogReview::query()
            ->where('catalog', Catalog::Teachers)
            ->where('status', ReviewStatus::Validated)
            ->whereIn('department_id', $teacher->adscriptions()->select('department_id'))
            ->whereHas('term', fn (Builder $term) => $term->where('ends_on', '>=', today()->toDateString()))
            ->orderBy('validated_at')
            ->first();
    }

    public function isReleased(Teacher $teacher): bool
    {
        return $this->releasingReview($teacher) !== null;
    }
}
