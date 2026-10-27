<?php

namespace App\Modules\Core\Navigation;

use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Queries\ScopedContext;

/**
 * Registro de la barra lateral: las entradas que cada módulo declara en navigation() de su
 * provider, en el orden de bootstrap/providers.php y, dentro de un módulo, en el que las declara.
 *
 * Core lo arma una vez, cuando todos los módulos ya arrancaron, y lo comparte entre peticiones
 * (Octane): es inmutable y lo que depende del usuario se calcula en visibleTo(), sin guardarlo.
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
}
