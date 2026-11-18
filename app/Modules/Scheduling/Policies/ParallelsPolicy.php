<?php

namespace App\Modules\Scheduling\Policies;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Scheduling\Enums\SchedulingPermission;

/**
 * Quién ve y quién arma los paralelos de un departamento en una modalidad (tarea 8.8; reglas-de-negocio.md, «Aprobaciones
 * y permisos», fila «Armar paralelos»):
 *
 * - Ven la pestaña «Paralelos» quienes ven la bandeja del departamento (`academic_load.proposal_review.view`): el jefe de
 *   departamento, el jefe de proyecto docente, Subdirección y el admin.
 * - Arma (ArmParallelGroups) el jefe del departamento que imparte la materia (`scheduling.parallels.arrange`, modalidad y
 *   unidad), de cualquier departamento (confirmado el 18 de noviembre de 2026); el jefe de proyecto docente no (supuesto
 *   por confirmar, maqueta B). Sin la dimensión campus: el jefe arma en todos los campus de su modalidad, como decide los
 *   acuerdos (M12).
 *
 * Registrada en SchedulingServiceProvider para Parallels, que no es un modelo.
 */
final class ParallelsPolicy
{
    use AuthorizesActor;

    public function view(User $user, Modality $modality, Department $department): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::ProposalReviewView, Target::of(modality: $modality->getKey(), unit: $department->getKey()));
    }

    public function arrange(User $user, Modality $modality, Department $department): bool
    {
        return $this->actor($user)->allows(SchedulingPermission::ParallelsArrange, Target::of(modality: $modality->getKey(), unit: $department->getKey()));
    }
}
