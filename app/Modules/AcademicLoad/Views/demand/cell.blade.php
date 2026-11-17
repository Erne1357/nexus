{{--
    El valor de una celda de «Demanda por materia»: `pedidos/sugeridos` en Mono, con el icono de tendencia si se aparta
    del umbral (el color nunca va solo); si la carrera no ha enviado, lo sugerido con el reloj en una caja punteada. Lo que
    leen los lectores de pantalla va aparte, con el nombre de la carrera (`$label`, «ISIC» o «Todo el Tec»).
    `$cell` es un DemandBoardCell; quien lo incluye pone el tono de la celda. `$bare`: la celda pendiente sin su caja
    punteada, cuando quien la incluye ya es la caja (las etiquetas de los renglones apilados).
--}}
@php($bare ??= false)
@if ($cell->pending)
    <span @class(['inline-flex items-center gap-1 font-mono tabular-nums text-neutral', 'rounded border border-dashed border-base-content/35 px-1.5 py-px' => ! $bare]) aria-hidden="true"><x-icon name="o-clock" class="h-3.5 w-3.5 shrink-0" />{{ $cell->suggested }}</span>
    <span class="sr-only">{{ $label }}: sin propuesta; cuenta {{ $cell->suggested }} {{ $cell->suggested === 1 ? 'grupo sugerido' : 'grupos sugeridos' }}.</span>
@else
    <span class="inline-flex items-center gap-1 whitespace-nowrap" aria-hidden="true">
        @if ($cell->large)
            <x-icon :name="$cell->difference() > 0 ? 'o-arrow-trending-up' : 'o-arrow-trending-down'" class="h-3.5 w-3.5 shrink-0" />
        @endif
        <b class="font-mono font-semibold tabular-nums">{{ $cell->counted }}</b><span @class(['font-mono tabular-nums', 'opacity-80' => $cell->large, 'text-neutral' => ! $cell->large])>/{{ $cell->suggested }}</span>
    </span>
    <span class="sr-only">{{ $label }}: {{ $cell->counted }} {{ $cell->counted === 1 ? 'grupo pedido' : 'grupos pedidos' }} de {{ $cell->suggested }} {{ $cell->suggested === 1 ? 'sugerido' : 'sugeridos' }}@if ($cell->large); se aparta {{ $board->settings->highlightThreshold }} % o más @endif.</span>
@endif
