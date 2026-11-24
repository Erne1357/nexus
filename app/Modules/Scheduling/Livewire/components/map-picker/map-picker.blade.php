{{--
    El mapa 3D del campus en modo «elegir» dentro de un cajón (tarea 14, diseño §6.5). Mismo marco que el Inicio
    (scheduling::map.stage), con alto propio y sin la columna del panel. Alpine llama a `window.UbicaTec.enablePick` al
    montarse y cada vez que cambian `eligible` o `rejected`; al desmontarse, `disablePick`. El mapa emite `room-picked`
    en la ventana con `{key}`. Sin la bandera `nexus.campus_map_3d` muestra un aviso en lugar del visor.
--}}
<div data-testid="map-picker" class="w-full"
    x-data="{
        apply(eligible, rejected) {
            const go = () => window.UbicaTec?.enablePick?.({ eligible: JSON.parse(eligible), rejected: JSON.parse(rejected) });
            if (window.UbicaTec?.enablePick) go(); else window.addEventListener('ubicatec:ready', go, { once: true });
        },
        init() { this.$nextTick(() => window.dispatchEvent(new CustomEvent('ubicatec:mount'))); },
        destroy() { window.UbicaTec?.disablePick?.(); },
    }"
    x-effect="apply(JSON.stringify($wire.eligible), JSON.stringify($wire.rejected))">
    @if (config('nexus.campus_map_3d'))
        @assets
            @vite(['resources/css/ubicatec.css', 'resources/js/ubicatec/main.js'])
        @endassets
        @include('scheduling::map.stage', ['map' => $map, 'lugar' => null, 'class' => 'ut-picker relative h-[65vh] min-h-96 w-full overflow-hidden rounded-box', 'testid' => 'map-picker-stage'])
    @else
        <div data-testid="map-picker-static" class="flex h-48 items-center justify-center rounded-box bg-base-200 text-sm text-base-content/70">
            El mapa en 3D está apagado en este entorno.
        </div>
    @endif
</div>
