<?php

use App\Modules\Scheduling\Queries\CampusMapQuery;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Inicio: el mapa del campus (tarea 1.24.1, ADR-0030), para todo el que tiene sesión (D6 del usuario, 20 de noviembre de
 * 2026), dentro de la app con la banda y el menú (D5). El mapa (resources/js/ubicatec) maneja su propio DOM y Livewire no
 * lo vuelve a pintar; sus datos reales (CampusMapQuery: lugares, departamentos y las clases del periodo en curso) van
 * embebidos como JSON, sin API. `?lugar=b:<edificio>` abre un lugar al cargar.
 *
 * Bandera `config('nexus.campus_map_3d')` (env CAMPUS_MAP_3D, por omisión true): con false vive un marcador estático con el
 * título y el periodo, sin assets de ubicatec ni visor (phpunit.xml la apaga para no pagar WebGL en las pruebas).
 */
new #[Layout('layouts::app', ['fullBleed' => true])] #[Title('Inicio')] class extends Component
{
    #[Url]
    public ?string $lugar = null;

    /**
     * @return array{map: array<string, mixed>}
     */
    public function with(CampusMapQuery $query): array
    {
        return ['map' => $query->forDay(CarbonImmutable::now('America/Ciudad_Juarez'))];
    }
};
