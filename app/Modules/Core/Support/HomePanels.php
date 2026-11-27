<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Enums\Role;

/**
 * El panel que reemplaza el bloque de Resumen (`/resumen`) de un puesto, y que pone el módulo dueño de ese puesto
 * (AC-15, tarea 1.67): Students registra el tablero del alumno con `register()` al arrancar, sin que Core lo importe.
 * Guarda solo el nombre del componente Livewire de cada puesto; el registro se llena en boot() y no cambia por
 * petición, así que es seguro con Octane (singleton, como CredentialPhotos).
 */
final class HomePanels
{
    /** @var array<string, string> valor del puesto (`Role::value`) → nombre del componente Livewire */
    private array $components = [];

    /**
     * @param  string  $role  el valor del puesto (`Role::value`): el módulo lo da como texto para no decidir por puesto con `Role::` (ADR-0018)
     */
    public function register(string $role, string $component): void
    {
        $this->components[$role] = $component;
    }

    /**
     * El componente que pinta el Resumen de `$role`, o null si el puesto usa el bloque de siempre.
     */
    public function for(Role $role): ?string
    {
        return $this->components[$role->value] ?? null;
    }
}
