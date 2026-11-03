<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;

/**
 * Quién arma, ve, corrige y envía la propuesta de una carrera, según docs/dominio/reglas-de-negocio.md,
 * "Aprobaciones y permisos" (filas "Ver la propuesta de carrera" y "Armar, corregir y enviar la propuesta de
 * carrera"):
 *
 * - Arma (StartCareerProposal), corrige (UpdateProposalItem) y envía (SubmitCareerProposal) el coordinador de
 *   la carrera en ese campus.
 * - La ve también la subdirección, en cualquier campus y solo lectura (supuesto por confirmar).
 *
 * covers() ya incluye al admin. Los jefes deciden la parte de su departamento desde su bandeja, con
 * ProposalReviewPolicy.
 */
final class CareerProposalPolicy
{
    use UsesScopedContext;

    public function create(User $user, Campus $campus, Career $career): bool
    {
        return $this->scope($user)->allows(AcademicLoadPermission::ProposalCreate, Target::of(campus: $campus->getKey(), career: $career->getKey()));
    }

    public function view(User $user, CareerProposal $proposal): bool
    {
        return $this->can($user, AcademicLoadPermission::ProposalView, $proposal);
    }

    public function update(User $user, CareerProposal $proposal): bool
    {
        return $this->can($user, AcademicLoadPermission::ProposalUpdate, $proposal);
    }

    public function submit(User $user, CareerProposal $proposal): bool
    {
        return $this->can($user, AcademicLoadPermission::ProposalSubmit, $proposal);
    }

    private function can(User $user, AcademicLoadPermission $permission, CareerProposal $proposal): bool
    {
        return $this->scope($user)->allows($permission, Target::of(campus: $proposal->campus_id, career: $proposal->career_id));
    }
}
