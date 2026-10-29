<?php

namespace App\Modules\Core\Database\Seeders\Concerns;

use RuntimeException;

/**
 * Para los seeders de datos demo: sus usuarios entran con la contraseña `password` y sin cambiarla, así que
 * nunca corren en producción (supuesto S5, por confirmar).
 */
trait RefusesProduction
{
    /**
     * @throws RuntimeException en producción
     */
    protected function refuseProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Los datos demo no se siembran en producción.');
        }
    }
}
