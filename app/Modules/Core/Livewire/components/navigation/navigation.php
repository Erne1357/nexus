<?php

use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Component;

/**
 * Barra lateral: las entradas del registro Navigation que el usuario puede ver.
 */
new class extends Component
{
    /**
     * @return array{items: list<NavigationItem>}
     */
    public function with(Navigation $navigation, ScopedContext $context): array
    {
        return ['items' => $navigation->visibleTo($context)];
    }
};
