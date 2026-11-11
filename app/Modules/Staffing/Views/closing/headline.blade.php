{{--
    La línea del estado de una carga: su icono con el tono (el color nunca va solo), el título («Faltan 21 para cerrar»,
    «Lista para cerrar», «Cerrada el 07/12/2026») y la línea de detalle. `$closing` es LoadClosing.
--}}
@php($headline = $closing->headline())
<div class="flex min-w-0 items-start gap-2.5" data-testid="load-headline">
    <x-icon :name="$headline['icon']" @class([
        'mt-0.5 h-5 w-5 shrink-0',
        'text-error' => $headline['tone'] === 'error',
        'text-warning' => $headline['tone'] === 'warning',
        'text-success' => $headline['tone'] === 'success',
        'text-info' => $headline['tone'] === 'info',
        'text-neutral' => $headline['tone'] === 'neutral',
    ]) aria-hidden="true" />
    <div class="min-w-0">
        <p class="font-semibold leading-snug" data-testid="load-headline-title">{{ $headline['title'] }}</p>
        <p class="text-sm text-neutral">{{ $headline['detail'] }}</p>
    </div>
</div>
