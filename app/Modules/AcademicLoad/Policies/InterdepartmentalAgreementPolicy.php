<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve, pide, decide y cancela los acuerdos entre departamentos, según docs/dominio/reglas-de-negocio.md,
 * "Aprobaciones y permisos" (filas "Ver acuerdos entre departamentos", "Proponer o aceptar préstamo de salón" y
 * "Proponer o aceptar grupo compartido"; confirmado el 7 de noviembre de 2026):
 *
 * - Ve un acuerdo quien tiene `academic_load.agreement.view` sobre el departamento que lo pide o sobre el que lo
 *   decide: la subdirección, el jefe de departamento y el jefe de proyecto docente (supuesto por confirmar).
 * - Pide un préstamo o propone un grupo (`request`) y lo cancela (`cancel`) el jefe del departamento que lo pide
 *   (`from`).
 * - Acepta o rechaza (`decide`) el jefe del departamento al que va dirigido (`to`): el dueño del salón o el
 *   departamento de la carrera.
 *
 * El admin pasa por la concesión implícita del registro.
 */
final class InterdepartmentalAgreementPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(AcademicLoadPermission::AgreementView);
    }

    public function view(User $user, InterdepartmentalAgreement $agreement): bool
    {
        $actor = $this->actor($user);

        return $actor->allows(AcademicLoadPermission::AgreementView, Target::of(campus: $agreement->campus_id, unit: $agreement->from_department_id))
            || $actor->allows(AcademicLoadPermission::AgreementView, Target::of(campus: $agreement->campus_id, unit: $agreement->to_department_id));
    }

    public function decide(User $user, InterdepartmentalAgreement $agreement): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::AgreementDecide, Target::of(campus: $agreement->campus_id, unit: $agreement->to_department_id));
    }

    public function cancel(User $user, InterdepartmentalAgreement $agreement): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::AgreementRequest, Target::of(campus: $agreement->campus_id, unit: $agreement->from_department_id));
    }

    public function request(User $user, Campus $campus, Department $from): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::AgreementRequest, Target::of(campus: $campus->getKey(), unit: $from->getKey()));
    }
}
