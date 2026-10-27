<?php

namespace App\Modules\Imports;

use App\Modules\Core\ModuleServiceProvider;
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
        $this->app->singleton(TemplateRegistry::class, fn (Application $app): TemplateRegistry => new TemplateRegistry(
            $app,
            self::TEMPLATES,
        ));
    }
}
