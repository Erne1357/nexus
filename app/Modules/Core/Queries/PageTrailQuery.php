<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Navigation\Navigation;
use Illuminate\Http\Request;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Mechanisms\HandleRouting\LivewirePageController;
use ReflectionClass;
use Throwable;

/**
 * Lo que la banda pone después de «Nexus» (DESIGN.md, "Estructura"): el área de la entrada del menú de la pantalla
 * (Navigation::forRoute) y su título, el que le da la pantalla (#[Title]) o, sin él, el nombre de la entrada. Lo leen la
 * banda (core::components.band) y la respuesta del login (LoginResponse), que con él guarda el lugar de la ruta en la
 * banda a la que llega la credencial, así esta cae donde la pinta la página siguiente (tarea 1.23, #694).
 */
final class PageTrailQuery
{
    public function __construct(private readonly Navigation $navigation) {}

    /**
     * @return array{area: ?string, title: ?string}
     */
    public function of(?string $route, ?string $title, Actor $context): array
    {
        $item = $route === null ? null : $this->navigation->forRoute($route, $context);

        return ['area' => $item?->area->label(), 'title' => $title ?? $item?->label];
    }

    /**
     * La de la página a la que lleva la dirección, si esa página tiene banda: una página completa de Livewire con el
     * layout de la aplicación que quien entra ve en el menú (o que no está en él, como el inicio). Null si no la tiene:
     * una descarga, una página pública, una de otro puesto o una dirección que no existe.
     *
     * @return array{area: ?string, title: ?string}|null
     */
    public function forUrl(string $url, Actor $context): ?array
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($url));
            $page = $route->getActionName() === LivewirePageController::class
                ? new ReflectionClass(app('livewire.factory')->resolveComponentClass($route->getAction('livewire_component')))
                : null;
        } catch (Throwable) {
            return null;
        }

        $shell = config('livewire.component_layout');
        if ($page === null || ($this->attribute($page, Layout::class)?->name ?? $shell) !== $shell) {
            return null;
        }

        $item = $route->getName() === null ? null : $this->navigation->forRoute($route->getName(), $context);
        if ($item !== null && ! $item->isVisibleTo($context)) {
            return null;
        }

        return $this->of($route->getName(), $this->attribute($page, Title::class)?->content, $context);
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<object>  $page
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    private function attribute(ReflectionClass $page, string $attribute): ?object
    {
        return ($page->getAttributes($attribute)[0] ?? null)?->newInstance();
    }
}
