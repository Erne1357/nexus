<?php

namespace App\Modules\Academic\Policies;

use App\Modules\Academic\Enums\AcademicPermission;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién administra planes de estudio, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila
 * "Administrar catálogos de Academic": `admin`). Solo el admin da de alta, edita, activa, desactiva y edita la
 * retícula. No hay `delete`: un plan no se borra, se desactiva (confirmado el 29 de octubre de 2026).
 *
 * Las materias por validar de la migración del SII (D19, confirmado el 5 de noviembre de 2026) las valida el jefe del
 * Departamento de Servicios Escolares: `validatePlacements`, `academic.placement.validate` sobre esa unidad. Quien
 * las valida también ve la lista de planes y la retícula de cada uno, de solo lectura.
 */
final class StudyPlanPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(AcademicPermission::StudyPlanView) || $this->validatePlacements($user);
    }

    public function view(User $user, StudyPlan $plan): bool
    {
        return $this->actor($user)->allows(AcademicPermission::StudyPlanView) || $this->validatePlacements($user);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(AcademicPermission::StudyPlanCreate);
    }

    /**
     * Sus datos y su estado; que ningún alumno lo tenga al cambiar de carrera lo exige UpdateStudyPlan
     * con PlanCareerChanging.
     */
    public function update(User $user, StudyPlan $plan): bool
    {
        return $this->actor($user)->allows(AcademicPermission::StudyPlanUpdate);
    }

    /**
     * La retícula del plan: materias por semestre, especialidades y seriación (Task 8 en adelante).
     */
    public function editGrid(User $user, StudyPlan $plan): bool
    {
        return $this->actor($user)->allows(AcademicPermission::StudyPlanEditGrid);
    }

    /**
     * Ver cuántas materias esperan semestre, la pestaña «Por validar» y resolverlas (D19): el permiso sobre la unidad
     * del Departamento de Servicios Escolares. Sin esa unidad en el organigrama, solo el admin. La unidad se busca
     * solo si algún puesto del usuario concede el permiso y no lo tiene sin límite de unidad (el admin).
     */
    public function validatePlacements(User $user): bool
    {
        $actor = $this->actor($user);

        if (! $actor->allowsAny(AcademicPermission::PlacementValidate)) {
            return false;
        }

        return $actor->allows(AcademicPermission::PlacementValidate, Target::of())
            || $actor->allows(AcademicPermission::PlacementValidate, Target::of(unit: app(OrgTree::class)->idOf(OrgTree::SCHOOL_SERVICES)));
    }
}
