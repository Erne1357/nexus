<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Authorization\Actor;
use Illuminate\Auth\Events\Login;

/**
 * Al iniciar sesión olvida el perfil activo que haya en la sesión (AZ-S4): Fortify regenera el id de la sesión
 * pero conserva sus datos, y un perfil de una sesión anterior no debe sobrevivir. El de omisión se fija otra vez
 * la primera vez que se resuelve (Actor::activeRole).
 */
final class ForgetActiveRole
{
    public function handle(Login $event): void
    {
        if (app()->bound('session')) {
            session()->forget(Actor::ACTIVE_ROLE);
        }
    }
}
