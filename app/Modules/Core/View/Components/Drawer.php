<?php

namespace App\Modules\Core\View\Components;

use Illuminate\Support\Str;
use Mary\View\Components\Drawer as MaryDrawer;

/**
 * El `x-drawer` de la app: el de MaryUI con un id de casilla propio de cada instancia (ADR-0023, issue #754).
 *
 * MaryUI deriva el id de la casilla de `md5(serialize($this))`, que solo mira las propiedades del constructor; dos drawers
 * de una misma página con los mismos atributos (`right`, `close-on-escape`) quedaban con el mismo id. El velo de cada drawer
 * es un `<label for="id">`, así que un clic fuera del drawer abierto encendía la casilla del PRIMER drawer de la página y
 * abría otro debajo. Sin `id` explícito, cada instancia saca uno único.
 */
class Drawer extends MaryDrawer
{
    private ?string $unique = null;

    public function id(): string
    {
        // Blade arma el componente por reflexión del constructor del padre, así que aquí no se toca.
        return $this->id ?? $this->unique ??= 'drawer-'.Str::lower(Str::random(12));
    }
}
