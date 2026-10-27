<?php

namespace App\Modules\Core;

use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Contracts\Foundation\Application;

final class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // `scoped`, nunca singleton: con Octane un singleton guardaría el alcance de un usuario
        // para las peticiones siguientes.
        $this->app->scoped(ScopedContext::class, fn (Application $app): ScopedContext => new ScopedContext(
            fn (): mixed => $app['auth']->user(),
            $app['session.store'],
        ));
    }
}
