<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve y edita los roles y los permisos de los puestos («Puestos y permisos», tarea 1.22): `core.authz.view` y
 * `core.authz.manage`, candados que solo tiene el admin (CF-03). Las abilities sin modelo (`viewAny`, `create`,
 * `managePositions`) se piden con la clase: `Gate::allows('managePositions', PermissionSet::class)`.
 *
 * - viewAny y view: ver los puestos, los roles y el catálogo de permisos.
 * - create, update y delete: dar de alta, editar (también desactivar, restablecer y aplicar lo nuevo de fábrica) y
 *   borrar roles.
 * - managePositions: dar y quitar roles y permisos sueltos a un puesto y restablecerlo.
 */
final class PermissionSetPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::AuthzView);
    }

    public function view(User $user, PermissionSet $set): bool
    {
        return $this->actor($user)->allows(CorePermission::AuthzView);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::AuthzManage);
    }

    public function update(User $user, PermissionSet $set): bool
    {
        return $this->actor($user)->allows(CorePermission::AuthzManage);
    }

    public function delete(User $user, PermissionSet $set): bool
    {
        return $this->actor($user)->allows(CorePermission::AuthzManage);
    }

    public function managePositions(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::AuthzManage);
    }
}
