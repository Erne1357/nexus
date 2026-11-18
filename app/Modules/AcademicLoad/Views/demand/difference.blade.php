{{--
    La diferencia de lo pedido contra lo sugerido de una celda del drawer de «Demanda por materia» (DemandBoardCell): 0 en
    neutro, o el número con signo y su porcentaje, con el icono de tendencia si llega al umbral (el color nunca va solo).
--}}
@php($signed = fn (int $n): string => $n > 0 ? "+{$n}" : ($n < 0 ? '−'.abs($n) : '0'))
@if ($cell->difference() === 0)
    <span class="font-mono text-neutral">0</span>
@else
    <span class="inline-flex items-center gap-1">
        @if ($cell->large)
            <x-icon :name="$cell->difference() > 0 ? 'o-arrow-trending-up' : 'o-arrow-trending-down'" class="h-3.5 w-3.5" aria-hidden="true" />
        @endif
        <span class="font-mono font-semibold tabular-nums">{{ $signed($cell->difference()) }}</span>
        @if ($cell->percent() !== null)
            <small class="font-mono tabular-nums">{{ $signed($cell->percent()) }} %</small>
        @endif
    </span>
@endif
