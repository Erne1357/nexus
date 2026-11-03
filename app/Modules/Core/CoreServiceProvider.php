<?php

namespace App\Modules\Core;

use App\Modules\Core\Authorization\AuthorizationSnapshots;
use App\Modules\Core\Authorization\DefaultScopeLabels;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Listeners\ForgetTermCalendar;
use App\Modules\Core\Livewire\ActiveRoleHook;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Livewire\ComponentHookRegistry;

final class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Estático y en register(): Livewire cablea sus hooks al arrancar (ComponentHookRegistry::boot()), y un hook
        // registrado después, con Livewire::componentHook() desde boot(), ya no se engancha.
        ComponentHookRegistry::register(ActiveRoleHook::class);

        // `scoped`, nunca singleton: con Octane un singleton guardaría el alcance de un usuario
        // para las peticiones siguientes.
        $this->app->scoped(ScopedContext::class, fn (Application $app): ScopedContext => new ScopedContext(
            fn (): mixed => $app['auth']->user(),
            $app->make(OrgTree::class),
            $app->make(AuthorizationSnapshots::class),
            $app->make(PermissionRegistry::class),
            $app['session.store'],
        ));

        // `scoped` por lo mismo: guarda en la instancia la huella y el mapa de la petición en curso.
        $this->app->scoped(OrgTree::class, fn (Application $app): OrgTree => new OrgTree($app['cache.store']));

        // `scoped` por lo mismo: memoriza los snapshots de nombramientos de la petición en curso.
        $this->app->scoped(AuthorizationSnapshots::class, fn (Application $app): AuthorizationSnapshots => new AuthorizationSnapshots($app['cache.store']));

        // Singleton porque es inmutable: las entradas de todos los módulos, sin nada del usuario.
        $this->app->singleton(Navigation::class, fn (Application $app): Navigation => Navigation::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));

        // Sin estado: nombra lo que Core no conoce. Un módulo con esos datos lo reemplaza en su register().
        $this->app->bindIf(ScopeLabels::class, DefaultScopeLabels::class);

        // Singleton porque es inmutable: el catálogo de permisos y las concesiones por puesto de todos
        // los módulos, sin nada del usuario ni de la petición.
        $this->app->singleton(PermissionRegistry::class, fn (Application $app): PermissionRegistry => PermissionRegistry::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(TermCalendarChanged::class, ForgetTermCalendar::class);

        // Se arma cuando ya arrancaron todos los módulos y antes de la primera petición, para que
        // Octane lo cree una vez por worker y no en cada petición.
        $this->app->booted(function (Application $app): void {
            $app->make(Navigation::class);
            $app->make(PermissionRegistry::class);
        });
    }

    public function navigation(): array
    {
        return [
            new NavigationItem('Inicio', 'home', 'o-home', NavigationArea::Academico),
            new NavigationItem('Campus', 'core.campuses', 'o-building-office-2', NavigationArea::Academico,
                ability: 'viewAny', arguments: [Campus::class]),
            new NavigationItem('Periodos', 'core.terms', 'o-calendar-days', NavigationArea::Academico,
                ability: 'viewAny', arguments: [Term::class], childRoutes: ['core.terms.show']),
        ];
    }
}
