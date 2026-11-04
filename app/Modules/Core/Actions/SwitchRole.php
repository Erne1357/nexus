<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Navigation\RecentPages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el perfil con que actúa la sesión (spec 8.1, AZ-S4): fija el puesto en `users.last_role` (el que se usa al
 * entrar la próxima vez) y deja en la bitácora «Cambió al perfil …» con el de antes y el de ahora, en una
 * transacción. Solo si se guardó, lo fija en la sesión; si el campus activo no es de los que ofrece el nuevo perfil,
 * lo olvida, y olvida las páginas recientes del menú (RecentPages), que eran de otro alcance.
 */
final class SwitchRole
{
    public function __construct(
        private readonly Actor $context,
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

        DB::transaction(function () use ($user, $role, $from): void {
            $user->forceFill(['last_role' => $role])->save();

            activity()
                ->performedOn($user)
                ->withProperties(['from' => $from?->value, 'to' => $role->value])
                ->log("Cambió al perfil {$role->label()}");
        });

        $this->session->put(Actor::ACTIVE_ROLE, $role->value);

        $campusId = $this->session->get(Actor::ACTIVE_CAMPUS);

        if ($campusId !== null && ! $this->context->campuses()->contains('id', $campusId)) {
            $this->session->forget(Actor::ACTIVE_CAMPUS);
        }

        $this->recentPages->forget();
    }
}
