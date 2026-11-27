<?php

namespace App\Modules\Academic\Policies;

use App\Modules\Academic\Enums\AcademicPermission;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién administra materias, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila
 * "Administrar catálogos de Academic": `admin`). Solo el admin ve, da de alta, edita, activa y
 * desactiva; en la fase 2 se abrirán a los jefes para validar. No hay `delete`: una materia no se
 * borra, se desactiva (confirmado el 29 de octubre de 2026).
 */
final class SubjectPolicy
{
    use AuthorizesActor;

    /**
     * Abrir el catálogo: quien ve las materias o quien cambia el tipo de espacio de las de su departamento (SEL-17); este
     * último ve solo las suyas y solo ese control (`viewCatalog` distingue al primero).
     */
    public function viewAny(User $user): bool
    {
        return $this->viewCatalog($user) || $this->actor($user)->allowsAny(AcademicPermission::SubjectSpaceSet);
    }

    /** El catálogo completo, con sus datos y acciones. */
    public function viewCatalog(User $user): bool
    {
        return $this->actor($user)->allowsAny(AcademicPermission::SubjectView);
    }

    public function view(User $user, Subject $subject): bool
    {
        return $this->actor($user)->allows(AcademicPermission::SubjectView);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(AcademicPermission::SubjectCreate);
    }

    /**
     * Sus datos y su estado (activar o desactivar); que nadie la necesite al desactivarla lo exige
     * UpdateSubject con SubjectDeactivating.
     */
    public function update(User $user, Subject $subject): bool
    {
        return $this->actor($user)->allows(AcademicPermission::SubjectUpdate);
    }

    /**
     * Cambiar el tipo de espacio que pide la materia (SEL-17): el jefe de su departamento y el admin; nadie más.
     */
    public function setSpace(User $user, Subject $subject): bool
    {
        return $this->setSpaceIn($user, $subject->department_id);
    }

    /**
     * Marcar si las horas de la materia cuentan en la carga del docente (PL-02, tarea 1.73): los mismos que cambian su
     * tipo de espacio, el jefe de su departamento y el admin (mismo permiso, `academic.subject.set_space`).
     */
    public function setTeacherHours(User $user, Subject $subject): bool
    {
        return $this->setSpaceIn($user, $subject->department_id);
    }

    /** Lo mismo, preguntado por el departamento de la materia (para listar solo las que puede cambiar). */
    public function setSpaceIn(User $user, string $departmentId): bool
    {
        return $this->actor($user)->allows(AcademicPermission::SubjectSpaceSet, Target::of(unit: $departmentId));
    }
}
