<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;

/**
 * Quién administra campus, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Abrir
 * periodo, calendario, perfil de campus": `admin`, `subdireccion`):
 *
 * - El admin da de alta campus y cambia sus datos, su estado y su perfil de flujo, en todos.
 * - La subdirección ve los campus de su alcance y cambia solo su perfil de flujo (`settings`).
 * - Nadie más los administra, y nadie los borra: es un catálogo, se desactiva (no hay `delete`).
 *
 * covers() ya da por cubierto todo al admin, así que las abilities por campus no lo repiten.
 */
final class CampusPolicy
{
    use UsesScopedContext;

    public function viewAny(User $user): bool
    {
        return $this->scope($user)->has(Role::Subdireccion);
    }

    public function view(User $user, Campus $campus): bool
    {
        return $this->scope($user)->covers(Role::Subdireccion, $campus);
    }

    public function create(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    /**
     * Código, nombre, modalidad y estado (activar o desactivar).
     */
    public function update(User $user, Campus $campus): bool
    {
        return $this->scope($user)->isAdmin();
    }

    /**
     * Solo el perfil de flujo (`settings`).
     */
    public function updateProfile(User $user, Campus $campus): bool
    {
        return $this->scope($user)->covers(Role::Subdireccion, $campus);
    }
}
