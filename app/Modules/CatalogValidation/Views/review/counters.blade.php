{{--
    Los contadores de una revisión (CatalogReviewQuery::summary): activos e inactivos (en un renglón de cifras; solo los
    activos se revisan, CV7), observaciones abiertas y las
    altas y las bajas contra la foto de la validación anterior (CV3), solo como cifras: «+7 altas · −6 bajas», en texto
    neutro lo que es cero (decidido por el usuario el 2 de noviembre de 2026). Una revisión sin foto anterior lo dice en
    texto neutro: «Primer periodo en Nexus» si ningún catálogo la tiene (`$firstTerm`; en el tablero lo dice la tarjeta
    de avance, una sola vez) o «Sin periodo anterior» si solo le falta a ella.

    Con `$inline` (la lista) todo va en un renglón y las altas filtran la tabla (`change`) y las bajas abren su drawer,
    porque ya no están en el catálogo. `$summary` es el arreglo de summary().
--}}
@php($inline ??= false)
@php($firstTerm ??= false)
@php($figures = ['added' => ['sign' => '+', 'one' => 'alta', 'many' => 'altas', 'none' => 'Sin altas', 'call' => "\$set('change', '".($change === 'added' ? '' : 'added')."')", 'title' => 'Mostrar solo las altas', 'testid' => 'filter-added'], 'removed' => ['sign' => '−', 'one' => 'baja', 'many' => 'bajas', 'none' => 'Sin bajas', 'call' => "\$set('showRemoved', true)", 'title' => 'Ver bajas', 'testid' => 'view-removed']])
@php($counts = $summary['added'] === null ? null : ['added' => count($summary['added']), 'removed' => count($summary['removed'])])

<div @class(['flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm' => $inline, 'space-y-2 border-t border-base-300 pt-3' => ! $inline]) @if ($inline) data-testid="review-counters" @endif>
    <p class="flex flex-wrap gap-x-4 text-sm">
        <span><b class="font-mono font-semibold tabular-nums">{{ $summary['active'] }}</b> {{ $summary['active'] === 1 ? 'activo' : 'activos' }}</span>
        <span class="text-neutral"><b class="font-mono font-semibold tabular-nums">{{ $summary['inactive'] }}</b> {{ $summary['inactive'] === 1 ? 'inactivo' : 'inactivos' }}</span>
    </p>

    @if ($counts === null)
        @if ($inline || ! $firstTerm)
            <p class="text-sm text-neutral" data-testid="first-term">{{ $firstTerm ? 'Primer periodo en Nexus' : 'Sin periodo anterior' }}</p>
        @endif
    @elseif ($counts['added'] === 0 && $counts['removed'] === 0)
        <p class="text-sm text-neutral" data-testid="changes">Sin altas ni bajas</p>
    @else
        <p class="flex flex-wrap items-center gap-x-2 text-sm" data-testid="changes">
            @foreach ($figures as $key => $figure)
                @if ($loop->last && ! $inline)
                    <span class="text-neutral" aria-hidden="true">·</span>
                @endif
                @if ($counts[$key] === 0)
                    <span class="text-neutral">{{ $figure['none'] }}</span>
                @elseif ($inline)
                    <button type="button" class="font-medium underline decoration-base-300 underline-offset-4 hover:decoration-current" wire:click="{{ $figure['call'] }}" @if ($key === 'added') aria-pressed="{{ $change === 'added' ? 'true' : 'false' }}" @endif title="{{ $figure['title'] }}" data-testid="{{ $figure['testid'] }}"><b class="font-mono font-semibold tabular-nums">{{ $figure['sign'] }}{{ $counts[$key] }}</b> {{ $counts[$key] === 1 ? $figure['one'] : $figure['many'] }}</button>
                @else
                    <span><b class="font-mono font-semibold tabular-nums">{{ $figure['sign'] }}{{ $counts[$key] }}</b> {{ $counts[$key] === 1 ? $figure['one'] : $figure['many'] }}</span>
                @endif
            @endforeach
        </p>
    @endif

    <p class="flex min-h-6.5 items-center text-sm">
        @if ($summary['open_flags'] > 0)
            <x-core::status-badge tone="warning" icon="o-chat-bubble-left-ellipsis" :label="trans_choice('{1} 1 observación abierta|[2,*] :count observaciones abiertas', $summary['open_flags'])" />
        @else
            <span class="text-neutral">Sin observaciones abiertas</span>
        @endif
    </p>
</div>
