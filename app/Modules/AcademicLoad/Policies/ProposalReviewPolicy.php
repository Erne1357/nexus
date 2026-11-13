<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve la bandeja de un departamento y decide su parte de cada propuesta de carrera, según
 * docs/dominio/reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Aceptar o regresar la parte del
 * departamento en una propuesta de carrera"):
 *
 * - Ven la bandeja de un departamento en una modalidad con el permiso `academic_load.proposal_review.view`: la
 *   subdirección, el jefe de departamento y el jefe de proyecto docente de ese departamento (supuesto por
 *   confirmar).
 * - Acepta (AcceptProposalReview) o regresa (ReturnProposalReview) la parte el jefe de ese departamento en la
 *   modalidad de la propuesta (AZ-S17), como quien propone la carga (supuesto por confirmar).
 *
 * El admin pasa por la concesión implícita del registro.
 */
final class ProposalReviewPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user, Modality $modality, Department $department): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::ProposalReviewView, Target::of(modality: $modality->getKey(), unit: $department->getKey()));
    }

    public function decide(User $user, ProposalReview $review): bool
    {
        $review->loadMissing('proposal');

        return $this->actor($user)->allows(AcademicLoadPermission::ProposalReviewDecide, Target::of(modality: $review->proposal->modality_id, unit: $review->department_id));
    }
}
