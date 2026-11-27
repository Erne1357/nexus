<?php

namespace App\Modules\Core\Livewire;

use Livewire\ComponentHook;
use Livewire\Exceptions\MethodNotFoundException;

/**
 * Tarea 4.8: `with()` arma los datos de la vista de un componente y es público, así que Livewire lo deja llamar desde el
 * cliente como una acción (`$wire.call('with')`) y devolvería en `returns` todo lo que arma: modelos completos y datos
 * que la vista no pinta. Livewire ya bloquea los hooks de ciclo de vida (`mount`, `hydrate*`, `rendering`…), pero no
 * `with`. Este hook lo rechaza igual que un método que no existe. Se registra en CoreServiceProvider::register(), como
 * ActiveRoleHook.
 */
final class BlockViewDataCalls extends ComponentHook
{
    /** @var list<string> */
    public const array BLOCKED = ['with'];

    public function call(string $method, array $params, callable $returnEarly): void
    {
        if (in_array($method, self::BLOCKED, true)) {
            throw new MethodNotFoundException($method);
        }
    }
}
