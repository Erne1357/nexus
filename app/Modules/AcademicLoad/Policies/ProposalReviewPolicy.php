<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;

/**
 * Quién ve la bandeja de un departamento y decide su parte de cada propuesta de carrera, según
 * docs/dominio/reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Aceptar o regresar la parte del
 * departamento en una propuesta de carrera"):
 *
 * - Ven la bandeja de un departamento en un campus quienes ven su carga (AcademicLoadPolicy::VIEWERS): la
 *   subdirección, el jefe de departamento y el jefe de proyecto docente de ese departamento (supuesto por
 *   confirmar).
 * - Acepta (AcceptProposalReview) o regresa (ReturnProposalReview) la parte el jefe de ese departamento en el
 *   campus de la propuesta, como quien propone la carga (supuesto por confirmar).
 *
 * covers() ya incluye al admin.
 */
final class ProposalReviewPolicy
{
    use UsesScopedContext;

    public function viewAny(User $user, Campus $campus, Department $department): bool
    {
        return $this->scope($user)->covers(AcademicLoadPolicy::VIEWERS, $campus, department: $department->getKey());
    }

    public function decide(User $user, ProposalReview $review): bool
    {
        $review->loadMissing('proposal');

        return $this->scope($user)->covers(Role::JefeDepartamento, $review->proposal->campus_id, department: $review->department_id);
    }
}
