<?php

namespace App\Modules\Imports;

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Templates\ImportTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Contracts\Foundation\Application;

final class ImportsServiceProvider extends ModuleServiceProvider
{
    /**
     * Plantillas de importación, una por catálogo, todas en Templates/. La tarea que escribe una
     * plantilla la agrega aquí.
     *
     * @var list<class-string<ImportTemplate>>
     */
    private const array TEMPLATES = [];

    public function register(): void
    {
        // Singleton porque es inmutable: guarda clases, y cada get() crea una plantilla nueva.
        $this->app->singleton(TemplateRegistry::class, fn (): TemplateRegistry => new TemplateRegistry(self::TEMPLATES));
    }

    public function navigation(): array
    {
        return [
            new NavigationItem('Importaciones', 'imports.wizard', 'o-arrow-up-tray', NavigationArea::Datos,
                ability: 'viewAny', arguments: [ImportBatch::class]),
        ];
    }

    public function boot(): void
    {
        parent::boot();

        // Como Navigation: se arma al arrancar, antes de la primera petición, para que Octane lo cree
        // una vez por worker y una plantilla mal declarada falle al arrancar y no al subir un lote.
        $this->app->booted(fn (Application $app): TemplateRegistry => $app->make(TemplateRegistry::class));
    }
}
