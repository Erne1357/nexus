<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Enums\Role;

/**
 * El panel que reemplaza el bloque de Resumen (`/resumen`) de un puesto, y que pone el módulo dueño de ese puesto
 * (AC-15, tarea 1.67): Students registra el tablero del alumno con `register()` al arrancar, sin que Core lo importe.
 * Guarda solo el nombre del componente Livewire de cada puesto y, si el módulo lo da, el subtítulo del encabezado de ese
 * panel (1.85: cada puesto dice lo suyo); el registro se llena en boot() y no cambia por
 * petición, así que es seguro con Octane (singleton, como CredentialPhotos).
 */
final class HomePanels
{
    /** @var array<string, string> valor del puesto (`Role::value`) → nombre del componente Livewire */
    private array $components = [];

    /** @var array<string, string> valor del puesto → subtítulo del encabezado del Resumen con su panel */
    private array $subtitles = [];

    /**
     * @param  string  $role  el valor del puesto (`Role::value`): el módulo lo da como texto para no decidir por puesto con `Role::` (ADR-0018)
     * @param  ?string  $subtitle  lo que dice el encabezado del Resumen con este panel; sin él, el de siempre
     */
    public function register(string $role, string $component, ?string $subtitle = null): void
    {
        $this->components[$role] = $component;

        if ($subtitle !== null) {
            $this->subtitles[$role] = $subtitle;
        }
    }

    /**
     * El componente que pinta el Resumen de `$role`, o null si el puesto usa el bloque de siempre.
     */
    public function for(Role $role): ?string
    {
        return $this->components[$role->value] ?? null;
    }

    /**
     * El subtítulo del encabezado del Resumen de `$role` que dio su módulo, o null si no dio uno.
     */
    public function subtitleFor(Role $role): ?string
    {
        return $this->subtitles[$role->value] ?? null;
    }
}
