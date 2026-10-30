<?php

namespace App\Modules\Academic\Policies;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;

/**
 * Quién administra planes de estudio, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila
 * "Administrar catálogos de Academic": `admin`). Solo el admin ve, da de alta, edita, activa,
 * desactiva y edita la retícula. No hay `delete`: un plan no se borra, se desactiva (confirmado el
 * 29 de octubre de 2026).
 */
final class StudyPlanPolicy
{
    use UsesScopedContext;

    public function viewAny(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    public function view(User $user, StudyPlan $plan): bool
    {
        return $this->scope($user)->isAdmin();
    }

    public function create(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    /**
     * Sus datos y su estado; que ningún alumno lo tenga al cambiar de carrera lo exige UpdateStudyPlan
     * con PlanCareerChanging.
     */
    public function update(User $user, StudyPlan $plan): bool
    {
        return $this->scope($user)->isAdmin();
    }

    /**
     * La retícula del plan: materias por semestre, especialidades y seriación (Task 8 en adelante).
     */
    public function editGrid(User $user, StudyPlan $plan): bool
    {
        return $this->scope($user)->isAdmin();
    }
}
