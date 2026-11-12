<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Navigation\Navigation;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * El aviso de la pantalla oculta (MN-02, DESIGN.md "Navigation"): cuando el admin abre una pantalla cuya entrada
 * del menú los demás no ven en el periodo activo, el layout se lo dice arriba del contenido, con el motivo. Lo
 * decide Navigation::hiddenReasonFor con la entrada de la ruta (la suya o la de su listado, si es una pantalla
 * hija); a cualquier otro usuario, y en una pantalla disponible, no le pinta nada.
 */
new class extends Component
{
    /**
     * Nombre de la ruta de la pantalla; por omisión, la de la petición que pinta el layout.
     */
    #[Locked]
    public ?string $route = null;

    public function mount(?string $route = null): void
    {
        $this->route = $route ?? request()->route()?->getName();
    }

    /**
     * @return array{reason: ?string}
     */
    public function with(Navigation $navigation, Actor $context): array
    {
        $item = $this->route === null ? null : $navigation->forRoute($this->route);

        return ['reason' => $item === null ? null : $navigation->hiddenReasonFor($item, $context)];
    }
};
