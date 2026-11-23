<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\RoleAccess;
use App\Modules\Core\Events\SettingsUpdated;
use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Olvida qué perfiles inician sesión (AC-21), para que la siguiente pregunta lo vuelva a leer: al terminar cada petición,
 * porque lo leído vale para una sola (Octane ya olvida lo `scoped` al empezar otra; sin Octane, y en las pruebas, la
 * instancia sigue); y si el parámetro cambió, también hace que Actor vuelva a cargar los puestos que cuentan, para que lo
 * que se decida después en la misma petición (o en la siguiente de una prueba) use el valor nuevo.
 */
final class ForgetRoleAccess
{
    public function __construct(private readonly RoleAccess $access) {}

    public function handle(SettingsUpdated|RequestHandled $event): void
    {
        if ($event instanceof RequestHandled) {
            $this->access->forget();

            return;
        }

        if (in_array(RoleAccess::SETTING, $event->keys, true)) {
            $this->access->forget();
            app(Actor::class)->reload();
        }
    }
}
