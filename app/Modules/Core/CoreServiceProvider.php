<?php

namespace App\Modules\Core;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\AuthorizationSnapshots;
use App\Modules\Core\Authorization\DefaultScopeLabels;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Console\SeedDemo;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Database\Seeders\TermHistorySeeder;
use App\Modules\Core\Database\Seeders\TermOpeningDemoSeeder;
use App\Modules\Core\Demo\DemoAdmin;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoPlan;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Listeners\ForgetActiveRole;
use App\Modules\Core\Listeners\ForgetTermCalendar;
use App\Modules\Core\Listeners\StampActingAs;
use App\Modules\Core\Livewire\ActiveRoleHook;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\NoUnitAdscriptions;
use App\Modules\Core\Queries\UnitAdscriptions;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\ComponentHookRegistry;
use Spatie\Activitylog\Models\Activity;

final class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Estático y en register(): Livewire cablea sus hooks al arrancar (ComponentHookRegistry::boot()), y un hook
        // registrado después, con Livewire::componentHook() desde boot(), ya no se engancha.
        ComponentHookRegistry::register(ActiveRoleHook::class);

        // `scoped`, nunca singleton: con Octane un singleton guardaría el alcance de un usuario
        // para las peticiones siguientes.
        $this->app->scoped(Actor::class, fn (Application $app): Actor => new Actor(
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

        // Solo lo usa la consola (`demo:seed`): se arma cuando se pide, con los pasos de todos los módulos.
        $this->app->bind(DemoPlan::class, fn (Application $app): DemoPlan => DemoPlan::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));

        // Sin estado: nombra lo que Core no conoce. Un módulo con esos datos lo reemplaza en su register().
        $this->app->bindIf(ScopeLabels::class, DefaultScopeLabels::class);

        // Sin estado: quién está adscrito a cada unidad del Organigrama. Faculty lo reemplaza con sus adscripciones.
        $this->app->bindIf(UnitAdscriptions::class, NoUnitAdscriptions::class);

        // Singleton porque es inmutable: el catálogo de permisos y las concesiones por puesto de todos
        // los módulos, sin nada del usuario ni de la petición.
        $this->app->singleton(PermissionRegistry::class, fn (Application $app): PermissionRegistry => PermissionRegistry::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([SeedDemo::class]);
        }

        Event::listen(TermCalendarChanged::class, ForgetTermCalendar::class);
        Event::listen(Login::class, ForgetActiveRole::class);
        Activity::creating(StampActingAs::class);

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
            new NavigationItem('Organigrama', 'core.org-chart', 'o-rectangle-group', NavigationArea::Academico,
                ability: 'viewAny', arguments: [OrgUnit::class]),
            new NavigationItem('Personas y puestos', 'core.people', 'o-users', NavigationArea::Academico,
                ability: 'viewAny', arguments: [RoleAssignment::class], childRoutes: ['core.people.show']),
        ];
    }

    public function permissions(): array
    {
        return [CorePermission::class];
    }

    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [
                CorePermission::CampusView,
                CorePermission::CampusUpdateProfile,
                CorePermission::TermView,
                CorePermission::TermCreate,
                CorePermission::TermUpdate,
                CorePermission::TermMakeCurrent,
                CorePermission::TermManageCalendar,
                CorePermission::TermOpen,
            ],
        ];
    }

    /**
     * Demo por puntos: lo base, el admin con la contraseña demo y los periodos llevan a «catálogos»; abrir 20271 en
     * los campus del volumen (en el masivo también 20263, la foto anterior de la validación) lleva a «validación».
     * Sin TermCalendarDemoSeeder: el calendario de 20271 lo escribe DemoRunner y no hay ventanas propias de Campus I.
     */
    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::Catalogs, 'Campus, organigrama, admin y periodos', function (DemoContext $demo): void {
                foreach ([CampusSeeder::class, OrgChartSeeder::class, AdminUserSeeder::class, DemoTermSeeder::class] as $seeder) {
                    $demo->runSeeder($seeder);
                }

                DemoAdmin::useDemoPassword();

                if ($demo->volume === DemoVolume::Massive) {
                    $demo->runSeeder(TermHistorySeeder::class);
                }
            }),
            new DemoStep(DemoCheckpoint::CatalogValidation, 'Abrir Ene-Jun 2027', fn (DemoContext $demo) => $demo->runSeeder(TermOpeningDemoSeeder::class, [
                'openings' => $demo->volume === DemoVolume::Massive
                    ? [$demo->termCode => $demo->campuses, '20263' => $demo->campuses]
                    : [$demo->termCode => $demo->campuses],
            ])),
        ];
    }

    public function demoChecks(): array
    {
        $openedIn = fn (DemoContext $demo): int => TermOpening::query()
            ->where('term_id', $demo->term()->getKey())
            ->whereIn('campus_id', Campus::query()->whereIn('code', $demo->campuses)->select('id'))
            ->count();

        return [
            new DemoCheck([DemoCheckpoint::Catalogs], 'Ene-Jun 2027 todavía sin abrir', fn (DemoContext $demo): true|string => ($opened = $openedIn($demo)) === 0
                ? true
                : "Ya está abierto en {$opened} campus."),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::CatalogValidation), 'Ene-Jun 2027 abierto en los campus del volumen', fn (DemoContext $demo): true|string => ($opened = $openedIn($demo)) === count($demo->campuses)
                ? true
                : sprintf('Abierto en %d de %d campus.', $opened, count($demo->campuses))),
            new DemoCheck(DemoCheckpoint::staged(), 'El admin entra con la contraseña demo', function (DemoContext $demo): true|string {
                $admin = $demo->admin();

                return Hash::check(DemoAdmin::PASSWORD, $admin->password) && ! $admin->must_change_password
                    ? true
                    : 'El admin no tiene la contraseña demo o debe cambiarla.';
            }),
        ];
    }
}
