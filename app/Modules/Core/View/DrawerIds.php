<?php

namespace App\Modules\Core\View;

/**
 * Cuenta, en una petición, cuántos drawers con la misma huella ya se pintaron en cada componente de Livewire (issue #754).
 *
 * Va como `scoped` (CoreServiceProvider): se reinicia en cada petición, así que con Octane no arrastra cuentas de otra. Una
 * actualización de Livewire vuelve a pintar el componente desde cero con la cuenta en cero, y sus drawers, en el mismo orden,
 * sacan el mismo número que en la carga de la página.
 */
final class DrawerIds
{
    /** @var array<string, int> */
    private array $seen = [];

    /**
     * La siguiente ocurrencia (1, 2, …) de una llave en esta petición.
     */
    public function next(string $key): int
    {
        return $this->seen[$key] = ($this->seen[$key] ?? 0) + 1;
    }
}
