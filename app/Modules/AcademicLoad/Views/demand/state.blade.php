{{--
    El estado de la propuesta de una carrera en «Demanda por materia» (DemandProposalState), compacto: su icono y su
    nombre en el tono de su insignia, como en el encabezado de la matriz de la maqueta C. `$state`; `$short` usa la
    etiqueta corta («Sin prop.»), con la completa para lectores de pantalla.
--}}
@php($short ??= false)
<span @class([
    'inline-flex items-center gap-1 whitespace-nowrap text-[11.5px] font-medium',
    'text-info' => $state->tone() === \App\Modules\Core\Enums\StatusTone::Info,
    'text-success' => $state->tone() === \App\Modules\Core\Enums\StatusTone::Success,
    'text-error' => $state->tone() === \App\Modules\Core\Enums\StatusTone::Error,
    'text-neutral' => $state->tone() === \App\Modules\Core\Enums\StatusTone::Draft,
])><x-icon :name="$state->icon()" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />@if ($short)<span aria-hidden="true">{{ $state->shortLabel() }}</span><span class="sr-only">{{ $state->label() }}</span>@else{{ $state->label() }}@endif</span>
