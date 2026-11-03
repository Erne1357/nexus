<?php

namespace App\Modules\Academic\Policies;

use App\Modules\Academic\Enums\AcademicPermission;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;

/**
 * Quién administra materias, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila
 * "Administrar catálogos de Academic": `admin`). Solo el admin ve, da de alta, edita, activa y
 * desactiva; en la fase 2 se abrirán a los jefes para validar. No hay `delete`: una materia no se
 * borra, se desactiva (confirmado el 29 de octubre de 2026).
 */
final class SubjectPolicy
{
    use UsesScopedContext;

    public function viewAny(User $user): bool
    {
        return $this->scope($user)->allowsAny(AcademicPermission::SubjectView);
    }

    public function view(User $user, Subject $subject): bool
    {
        return $this->scope($user)->allows(AcademicPermission::SubjectView);
    }

    public function create(User $user): bool
    {
        return $this->scope($user)->allows(AcademicPermission::SubjectCreate);
    }

    /**
     * Sus datos y su estado (activar o desactivar); que nadie la necesite al desactivarla lo exige
     * UpdateSubject con SubjectDeactivating.
     */
    public function update(User $user, Subject $subject): bool
    {
        return $this->scope($user)->allows(AcademicPermission::SubjectUpdate);
    }
}
