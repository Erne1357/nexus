<?php

namespace App\Modules\Core;

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
