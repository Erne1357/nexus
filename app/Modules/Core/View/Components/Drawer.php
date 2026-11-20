<?php

namespace App\Modules\Core\View\Components;

use App\Modules\Core\View\DrawerIds;
use Livewire\Component;
use Livewire\Livewire;
use Mary\View\Components\Drawer as MaryDrawer;

/**
 * El `x-drawer` de la app: el de MaryUI con un id de casilla único en la página y estable entre renders (ADR-0023, issue #754).
 *
 * MaryUI deriva el id de la casilla de `md5(serialize($this))`, que solo mira las propiedades del constructor; dos drawers
 * de una misma página con los mismos atributos (`right`, `close-on-escape`) quedaban con el mismo id. El velo de cada drawer
 * es un `<label for="id">`, así que un clic fuera del drawer abierto encendía la casilla del PRIMER drawer de la página y
 * abría otro debajo.
 *
 * Sin `id` explícito, el id es determinista: la huella de MaryUI, el componente de Livewire que lo pinta y el número de
 * ocurrencia de esa huella dentro de ese componente en esta petición (DrawerIds, `scoped`). Así dos drawers iguales en la
 * página tienen ids distintos y una actualización de Livewire vuelve a dar los mismos, sin que el morph cambie `id` y `for`
 * de un drawer abierto (que sí pasaba con un id aleatorio por render).
 */
class Drawer extends MaryDrawer
{
    private ?string $stable = null;

    public function id(): string
    {
        // Blade arma el componente por reflexión del constructor del padre, así que aquí no se toca.
        if ($this->id !== null) {
            return $this->id;
        }

        return $this->stable ??= $this->stableId();
    }

    private function stableId(): string
    {
        // Fuera de un render de Livewire, current() da false (last() de una pila vacía): la página cuenta como un solo componente.
        $current = Livewire::current();
        $component = $current instanceof Component ? $current->getId() : 'page';

        return $this->uuid.'-'.$component.'-'.app(DrawerIds::class)->next($component.'|'.$this->uuid);
    }
}
