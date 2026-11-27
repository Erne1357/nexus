<?php

namespace App\Modules\Academic\Policies;

use App\Modules\Academic\Enums\AcademicPermission;
use App\Modules\Academic\Models\Career;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién administra carreras, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila
 * "Administrar catálogos de Academic": `admin`). Solo el admin ve, da de alta, edita, activa o
 * desactiva y borra; en la fase 2 se abrirán a los jefes para validar.
 */
final class CareerPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(AcademicPermission::CareerView);
    }

    public function view(User $user, Career $career): bool
    {
        return $this->actor($user)->allows(AcademicPermission::CareerView);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(AcademicPermission::CareerCreate);
    }

    /**
     * Clave, nombre y estado (activar o desactivar).
     */
    public function update(User $user, Career $career): bool
    {
        return $this->actor($user)->allows(AcademicPermission::CareerUpdate);
    }

    /**
     * Borrado lógico; que no tenga planes de estudio lo exige DeleteCareer.
     */
    public function delete(User $user, Career $career): bool
    {
        return $this->actor($user)->allows(AcademicPermission::CareerDelete);
    }
}
