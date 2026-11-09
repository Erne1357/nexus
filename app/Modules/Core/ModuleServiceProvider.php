<?php

namespace App\Modules\Core;

use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Navigation\NavigationItem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionClass;

/**
 * Base de todo <Modulo>ServiceProvider: registra por convención, a partir de la carpeta
 * del módulo (donde vive el provider: app/Modules/<Modulo>/<Modulo>ServiceProvider.php),
 * solo lo que exista, salvo el namespace Livewire. El alias del módulo, kebab-case de su
 * carpeta (Core → core, AcademicLoad → academic-load), es el namespace de vistas, Livewire
 * y traducciones.
 *
 * - Routes/web.php        rutas con middleware `web` (el prefijo y `auth` los pone el módulo)
 * - Database/Migrations   migraciones
 * - Views                 vistas Blade: view('<alias>::<vista>')
 * - Livewire              componentes MFC: '<alias>::pages.<nombre>' y '<alias>::components.<nombre>'
 * - Lang                  traducciones: __('<alias>::<archivo>.<clave>')
 * - Policies              Policies/<Modelo>Policy ↔ Models/<Modelo> lo resuelve el auto-descubrimiento
 *                         de Laravel; $policies declara las que la convención no alcanza.
 *
 * Las entradas del menú del módulo se declaran en navigation(); Core las junta en el
 * registro Navigation al arrancar.
 *
 * Un módulo que sobrescriba boot() debe llamar a parent::boot().
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Policies que la convención no alcanza (p. ej. modelos de un paquete).
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [];

    /**
     * Raíz del módulo, calculada una vez por instancia del provider (nunca estática: Octane).
     */
    private ?string $moduleRoot = null;

    public function boot(): void
    {
        $alias = $this->moduleAlias();

        if (is_file($routes = $this->modulePath('Routes/web.php'))) {
            Route::middleware('web')->group(fn () => $this->loadRoutesFrom($routes));
        }

        if (is_dir($migrations = $this->modulePath('Database/Migrations'))) {
            $this->loadMigrationsFrom($migrations);
        }

        if (is_dir($views = $this->modulePath('Views'))) {
            $this->loadViewsFrom($views, $alias);
        }

        // Única excepción a "solo lo que exista": sin el namespace, make:livewire no puede
        // crear el primer componente del módulo. Livewire resuelve con is_dir, así que no cuesta.
        Livewire::addNamespace($alias, viewPath: $this->modulePath('Livewire'));

        if (is_dir($lang = $this->modulePath('Lang'))) {
            $this->loadTranslationsFrom($lang, $alias);
        }

        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * Entradas del menú que aporta el módulo, cada una con su área, en el orden en que se
     * muestran dentro de ella. Core las junta, en el orden de bootstrap/providers.php, en el registro
     * inmutable Navigation.
     *
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return [];
    }

    /**
     * Enums de permisos que declara el módulo (cada uno implementa Permission). Core los junta en el
     * registro inmutable PermissionRegistry, que rechaza claves repetidas o que no sean
     * `modulo.recurso.accion`.
     *
     * @return list<class-string<Permission>>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * Permisos que el módulo concede a cada puesto, con llave `Role->value`. Solo se pueden conceder
     * permisos declarados; el admin no se lista: tiene todo menos adminDenies().
     *
     * @return array<string, list<Permission>>
     */
    public function grants(): array
    {
        return [];
    }

    /**
     * Permisos declarados que el admin no tiene aunque tenga todo lo demás.
     *
     * @return list<Permission>
     */
    public function adminDenies(): array
    {
        return [];
    }

    /**
     * Pasos con los que el módulo siembra la demo por puntos (`php artisan demo:seed --hasta=`). Un punto es el
     * inicio de una etapa del proceso (`DemoCheckpoint`); un paso **lleva a** un punto terminando la etapa anterior
     * (validar todos los catálogos lleva a «propuesta»). Core los corre por punto en el orden de
     * bootstrap/providers.php y, dentro del módulo, en el de esta lista. Solo en consola, nunca en producción.
     *
     * @return list<DemoStep>
     */
    public function demoSteps(): array
    {
        return [];
    }

    /**
     * Lo que los pasos del módulo garantizan al llegar a cada punto (conteos baratos). El comando las imprime y sale
     * con error si una falla; la prueba de la demo por puntos las revisa en cada punto.
     *
     * @return list<DemoCheck>
     */
    public function demoChecks(): array
    {
        return [];
    }

    /**
     * Eventos del módulo que se descartan mientras se siembra la demo por puntos (recálculos caros por cada cambio).
     * El módulo que los silencia agrega un paso suyo que recalcula una sola vez.
     *
     * @return list<class-string>
     */
    public function demoQuietEvents(): array
    {
        return [];
    }

    protected function moduleAlias(): string
    {
        return Str::kebab(basename($this->modulePath()));
    }

    protected function modulePath(string $path = ''): string
    {
        $this->moduleRoot ??= dirname((new ReflectionClass(static::class))->getFileName());

        return $path === '' ? $this->moduleRoot : $this->moduleRoot.'/'.$path;
    }
}
