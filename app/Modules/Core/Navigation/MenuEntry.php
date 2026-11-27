<?php

namespace App\Modules\Core\Navigation;

/**
 * Una entrada tal como la ve un usuario en el menú: la entrada del registro y, si no está disponible en
 * el periodo activo y aun así la ve (el admin, MN-02), el motivo por el que los demás no la ven.
 */
final readonly class MenuEntry
{
    public function __construct(
        public NavigationItem $item,
        public ?string $hiddenReason = null,
    ) {}
}
