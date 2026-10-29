<?php

namespace App\Modules\Core;

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
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

        // Singleton porque es inmutable: las entradas de todos los módulos, sin nada del usuario.
        $this->app->singleton(Navigation::class, fn (Application $app): Navigation => Navigation::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        // Se arma cuando ya arrancaron todos los módulos y antes de la primera petición, para que
        // Octane lo cree una vez por worker y no en cada petición.
        $this->app->booted(fn (Application $app): Navigation => $app->make(Navigation::class));
    }

    public function navigation(): array
    {
        return [
            new NavigationItem('Inicio', 'home', 'o-home', NavigationArea::Academico),
            new NavigationItem('Campus', 'core.campuses', 'o-building-office-2', NavigationArea::Academico,
                ability: 'viewAny', arguments: [Campus::class]),
        ];
    }
}
