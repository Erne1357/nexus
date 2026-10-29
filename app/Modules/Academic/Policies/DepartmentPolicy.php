<?php

namespace App\Modules\Academic\Policies;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;

/**
 * Quién administra departamentos, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila
 * "Administrar catálogos de Academic": `admin`). Solo el admin ve, da de alta, edita, activa o
 * desactiva y borra; en la fase 2 se abrirán a los jefes para validar.
 */
final class DepartmentPolicy
{
    use UsesScopedContext;

    public function viewAny(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    public function view(User $user, Department $department): bool
    {
        return $this->scope($user)->isAdmin();
    }

    public function create(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    /**
     * Clave, nombre y estado (activar o desactivar).
     */
    public function update(User $user, Department $department): bool
    {
        return $this->scope($user)->isAdmin();
    }

    /**
     * Borrado lógico; que no tenga carreras ni materias lo exige DeleteDepartment.
     */
    public function delete(User $user, Department $department): bool
    {
        return $this->scope($user)->isAdmin();
    }
}
