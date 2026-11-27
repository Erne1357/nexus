{{--
    El mapa del campus del Inicio (tarea 1.24.1). El visor 3D y su panel los arma resources/js/ubicatec con los datos de
    #ubicatec-data; wire:ignore evita que Livewire los vuelva a pintar. Estilos aislados bajo #ubicatec-root (ADR-0030).
--}}
@if (config('nexus.campus_map_3d'))
    @assets
        @vite(['resources/css/ubicatec.css', 'resources/js/ubicatec/main.js'])
    @endassets
@include('scheduling::map.stage', ['map' => $map, 'lugar' => $lugar, 'class' => 'absolute inset-0', 'testid' => 'campus-map'])
@else
<div data-testid="campus-map-static" class="absolute inset-0 flex items-center justify-center bg-base-200">
    <div class="text-center">
        <h1 class="text-2xl font-semibold">Mapa del campus</h1>
        <p class="text-base-content/70">{{ collect([$map['campus'], isset($map['term']['name']) ? ($map['classes'] === [] ? 'sin clases publicadas en ' : 'clases de ').$map['term']['name'] : null])->filter()->implode(' · ') }}</p>
    </div>
</div>
@endif
