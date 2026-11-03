<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Navigation\RecentPages;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;

/**
 * Cambia el perfil con que actúa la sesión (spec 8.1, AZ-S4): fija el puesto en la sesión y en `users.last_role`
 * (el que se usa al entrar la próxima vez). Si el campus activo no es de los que ofrece el nuevo perfil, lo olvida,
 * y olvida las páginas recientes del menú (RecentPages), que eran de otro alcance. Es una preferencia más la sesión,
 * así que no abre transacción; deja en la bitácora «Cambió al perfil …» con el de antes y el de ahora.
 */
final class SwitchRole
{
    public function __construct(
        private readonly ScopedContext $context,
        private readonly Session $session,
        private readonly RecentPages $recentPages,
    ) {}

    /**
     * @throws AuthorizationException si no hay usuario o no tiene ese puesto
     */
    public function handle(Role $role): void
    {
        $user = $this->context->user();

        if ($user === null || ! in_array($role, $this->context->heldRoles(), true)) {
            throw new AuthorizationException('No tienes ese puesto.');
        }

        $from = $this->context->activeRole();

        $this->session->put(ScopedContext::ACTIVE_ROLE, $role->value);
        $user->forceFill(['last_role' => $role])->save();

        $campusId = $this->session->get(ScopedContext::ACTIVE_CAMPUS);

        if ($campusId !== null && ! $this->context->campuses()->contains('id', $campusId)) {
            $this->session->forget(ScopedContext::ACTIVE_CAMPUS);
        }

        $this->recentPages->forget();

        activity()
            ->performedOn($user)
            ->withProperties(['from' => $from?->value, 'to' => $role->value])
            ->log("Cambió al perfil {$role->label()}");
    }
}
