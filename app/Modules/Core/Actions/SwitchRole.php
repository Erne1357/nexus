<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Navigation\RecentPages;
use App\Modules\Core\Queries\RoleTitleQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el perfil con que actúa la sesión (spec 8.1, AZ-S4): fija el puesto en `users.last_role` (el que se usa al
 * entrar la próxima vez) y deja en la bitácora «Cambió al perfil …» con el de antes y el de ahora, en una
 * transacción. El perfil se nombra como en la credencial (RoleTitleQuery: el jefe de Servicios Escolares, por su
 * unidad, AZ-02). Solo si se guardó, lo fija en la sesión y olvida las páginas recientes del menú (RecentPages), que
 * eran de otro alcance. El periodo de la credencial se conserva; campus no hay (#415: AZ-S6 quedó sin efecto).
 */
final class SwitchRole
{
    public function __construct(
        private readonly Actor $context,
        private readonly Session $session,
        private readonly RecentPages $recentPages,
        private readonly RoleTitleQuery $titles,
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
        $title = $this->titles->of($this->context, $role)['title'];

        DB::transaction(function () use ($user, $role, $from, $title): void {
            $user->forceFill(['last_role' => $role])->save();

            activity()
                ->performedOn($user)
                ->withProperties(['from' => $from?->value, 'to' => $role->value])
                ->log("Cambió al perfil {$title}");
        });

        $this->session->put(Actor::ACTIVE_ROLE, $role->value);

        $this->recentPages->forget();
    }
}
