<?php

namespace App\Modules\Academic\Policies;

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;

/**
 * Quién administra carreras, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila
 * "Administrar catálogos de Academic": `admin`). Solo el admin ve, da de alta, edita, activa o
 * desactiva y borra; en la fase 2 se abrirán a los jefes para validar.
 */
final class CareerPolicy
{
    use UsesScopedContext;

    public function viewAny(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    public function view(User $user, Career $career): bool
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
    public function update(User $user, Career $career): bool
    {
        return $this->scope($user)->isAdmin();
    }

    /**
     * Borrado lógico; que no tenga planes de estudio lo exige DeleteCareer.
     */
    public function delete(User $user, Career $career): bool
    {
        return $this->scope($user)->isAdmin();
    }
}
