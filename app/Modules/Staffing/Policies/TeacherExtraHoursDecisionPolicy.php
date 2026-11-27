<?php

namespace App\Modules\Staffing\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\StaffingPermission;

/**
 * PL-04 (tarea 1.81): quién decide las horas extra (honorarios) de un docente. Quien tiene `staffing.honorarios.authorize`
 * sobre alguno de los departamentos a los que el docente está adscrito: el jefe de ese departamento (si tiene varios, cualquiera
 * de sus jefes; supuesto por confirmar) y el admin por la concesión implícita. El permiso es de la modalidad de la pantalla
 * (como la carga por docente) y de la unidad.
 */
final class TeacherExtraHoursDecisionPolicy
{
    use AuthorizesActor;

    public function decide(User $user, Teacher $teacher, Modality $modality): bool
    {
        $actor = $this->actor($user);

        return $teacher->adscriptions()->pluck('department_id')->contains(
            fn (string $departmentId): bool => $actor->allows(StaffingPermission::HonorariosAuthorize, Target::of(modality: $modality->getKey(), unit: $departmentId)),
        );
    }
}
