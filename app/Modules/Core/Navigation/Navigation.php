<?php

namespace App\Modules\Core\Navigation;

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Queries\ScopedContext;

/**
 * Registro de la barra lateral: las entradas que cada módulo declara en navigation() de su
 * provider, en el orden de bootstrap/providers.php y, dentro de un módulo, en el que las declara.
 * El menú las agrupa por área, en el orden de NavigationArea.
 *
 * Core lo arma una vez, cuando todos los módulos ya arrancaron, y lo comparte entre peticiones
 * (Octane): es inmutable y lo que depende del usuario se calcula en visibleTo() y visibleByArea(),
 * sin guardarlo.
 */
final readonly class Navigation
{
    /**
     * @param  list<NavigationItem>  $items
     */
    public function __construct(private array $items) {}

    /**
     * @param  iterable<ModuleServiceProvider>  $modules
     */
    public static function fromModules(iterable $modules): self
    {
        $items = [];

        foreach ($modules as $module) {
            array_push($items, ...$module->navigation());
        }

        return new self($items);
    }

    /**
     * Todas las entradas declaradas, para cualquier usuario.
     *
     * @return list<NavigationItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Las entradas que ve el usuario del contexto; ninguna sin usuario activo.
     *
     * @return list<NavigationItem>
     */
    public function visibleTo(ScopedContext $context): array
    {
        return array_values(array_filter(
            $this->items,
            fn (NavigationItem $item): bool => $item->isVisibleTo($context),
        ));
    }

    /**
     * Las entradas que ve el usuario del contexto, agrupadas por área: las áreas en el orden de
     * NavigationArea y solo las que tienen al menos una entrada visible; dentro de cada área, las
     * entradas en el orden del registro.
     *
     * @return list<array{area: NavigationArea, items: non-empty-list<NavigationItem>}>
     */
    public function visibleByArea(ScopedContext $context): array
    {
        $visible = $this->visibleTo($context);
        $groups = [];

        foreach (NavigationArea::cases() as $area) {
            $items = array_values(array_filter($visible, fn (NavigationItem $item): bool => $item->area === $area));

            if ($items !== []) {
                $groups[] = ['area' => $area, 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * La entrada declarada para una ruta, la vea o no el usuario (p. ej. para mostrar
     * «área › pantalla»); la primera si varias declaran la misma, y null si ninguna la declara.
     */
    public function forRoute(string $route): ?NavigationItem
    {
        foreach ($this->items as $item) {
            if ($item->route === $route) {
                return $item;
            }
        }

        return null;
    }
}
