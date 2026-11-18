<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve el tablero «Demanda por materia» (tarea 5.7; reglas-de-negocio.md, «Aprobaciones y permisos», «Ver la
 * demanda por materia»; confirmado el 16 de noviembre de 2026). Es de consulta para todos: no hay ability de escribir.
 *
 * - La lente del departamento (`academic_load.demand.view_department`, modalidad y unidad): el jefe de departamento y
 *   el jefe de proyecto docente, la de su departamento; Subdirección y el admin, la de cualquiera.
 * - La lente de la carrera (`academic_load.demand.view_career`, modalidad y carrera): el coordinador, la de su carrera.
 *
 * Registrada en AcademicLoadServiceProvider para DemandBoard, que no es un modelo.
 */
final class DemandBoardPolicy
{
    use AuthorizesActor;

    /**
     * Si entra a la pantalla: puede ver alguna de las dos lentes.
     */
    public function viewAny(User $user): bool
    {
        $actor = $this->actor($user);

        return $actor->allowsAny(AcademicLoadPermission::DemandViewDepartment) || $actor->allowsAny(AcademicLoadPermission::DemandViewCareer);
    }

    public function viewDepartment(User $user, Modality $modality, Department $department): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::DemandViewDepartment, Target::of(modality: $modality->getKey(), unit: $department->getKey()));
    }

    public function viewCareer(User $user, Modality $modality, Career $career): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::DemandViewCareer, Target::of(modality: $modality->getKey(), career: $career->getKey()));
    }
}
