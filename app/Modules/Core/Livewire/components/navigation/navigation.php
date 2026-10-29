<?php

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Component;

/**
 * Barra lateral: las entradas del registro Navigation que el usuario puede ver, agrupadas por área.
 */
new class extends Component
{
    /**
     * @return array{groups: list<array{area: NavigationArea, items: non-empty-list<NavigationItem>}>}
     */
    public function with(Navigation $navigation, ScopedContext $context): array
    {
        return ['groups' => $navigation->visibleByArea($context)];
    }
};
