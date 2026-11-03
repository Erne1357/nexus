<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Auth\Events\Login;

/**
 * Al iniciar sesión olvida el perfil activo que haya en la sesión (AZ-S4): Fortify regenera el id de la sesión
 * pero conserva sus datos, y un perfil de una sesión anterior no debe sobrevivir. El de omisión se fija otra vez
 * la primera vez que se resuelve (ScopedContext::activeRole).
 */
final class ForgetActiveRole
{
    public function handle(Login $event): void
    {
        if (app()->bound('session')) {
            session()->forget(ScopedContext::ACTIVE_ROLE);
        }
    }
}
