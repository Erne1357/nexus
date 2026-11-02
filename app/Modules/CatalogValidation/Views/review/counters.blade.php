{{--
    Los contadores de una revisión (CatalogReviewQuery::summary): activos e inactivos, observaciones abiertas y las
    altas y las bajas contra la foto de la validación anterior (CV3), solo como cifras: «+7 altas · −6 bajas», en texto
    neutro lo que es cero (decidido por el usuario el 2 de noviembre de 2026). Sin foto no se dice nada aquí: «Primer
    periodo en Nexus» va una sola vez, en la tarjeta de avance o en la lista.

    Con `$inline` (la lista) todo va en un renglón y las altas filtran la tabla (`change`) y las bajas abren su drawer,
    porque ya no están en el catálogo. `$summary` es el arreglo de summary().
--}}
@php($inline ??= false)
@php($added = $summary['added'] === null ? null : count($summary['added']))
@php($removed = $summary['removed'] === null ? null : count($summary['removed']))
@if ($inline)
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm" data-testid="review-counters">
        <span><b class="font-mono font-semibold tabular-nums">{{ $summary['active'] }}</b> activos</span>
        <span class="text-neutral"><b class="font-mono font-semibold tabular-nums">{{ $summary['inactive'] }}</b> inactivos</span>
        @if ($summary['open_flags'] > 0)
            <x-core::status-badge tone="warning" icon="o-chat-bubble-left-ellipsis" :label="trans_choice('{1} 1 observación abierta|[2,*] :count observaciones abiertas', $summary['open_flags'])" />
        @else
            <span class="text-neutral">Sin observaciones abiertas</span>
        @endif

        @if ($added === null)
            <span class="text-neutral" data-testid="first-term">Primer periodo en Nexus</span>
        @elseif ($added === 0 && $removed === 0)
            <span class="text-neutral" data-testid="changes">Sin altas ni bajas</span>
        @else
            <span class="flex items-center gap-x-3" data-testid="changes">
                @if ($added === 0)
                    <span class="text-neutral">Sin altas</span>
                @else
                    <button type="button" class="font-medium underline decoration-base-300 underline-offset-4 hover:decoration-current" wire:click="$set('change', '{{ $change === 'added' ? '' : 'added' }}')" aria-pressed="{{ $change === 'added' ? 'true' : 'false' }}" title="Mostrar solo las altas" data-testid="filter-added"><b class="font-mono font-semibold tabular-nums">+{{ $added }}</b> {{ $added === 1 ? 'alta' : 'altas' }}</button>
                @endif
                @if ($removed === 0)
                    <span class="text-neutral">Sin bajas</span>
                @else
                    <button type="button" class="font-medium underline decoration-base-300 underline-offset-4 hover:decoration-current" wire:click="$set('showRemoved', true)" title="Ver bajas" data-testid="view-removed"><b class="font-mono font-semibold tabular-nums">−{{ $removed }}</b> {{ $removed === 1 ? 'baja' : 'bajas' }}</button>
                @endif
            </span>
        @endif
    </div>
@else
    <div class="space-y-3">
        <dl class="grid grid-cols-2 gap-3 border-y border-base-300 py-3">
            <div class="flex flex-col-reverse"><dt class="text-sm text-neutral">Activos</dt><dd class="font-mono text-2xl font-semibold leading-tight">{{ $summary['active'] }}</dd></div>
            <div class="flex flex-col-reverse"><dt class="text-sm text-neutral">Inactivos</dt><dd class="font-mono text-2xl font-semibold leading-tight">{{ $summary['inactive'] }}</dd></div>
        </dl>

        @if ($added !== null)
            <p class="text-sm" data-testid="changes">
                @if ($added === 0 && $removed === 0)
                    <span class="text-neutral">Sin altas ni bajas</span>
                @else
                    <span @class(['text-neutral' => $added === 0])>@if ($added === 0) Sin altas @else <b class="font-mono font-semibold tabular-nums">+{{ $added }}</b> {{ $added === 1 ? 'alta' : 'altas' }} @endif</span>
                    <span class="text-neutral" aria-hidden="true">·</span>
                    <span @class(['text-neutral' => $removed === 0])>@if ($removed === 0) Sin bajas @else <b class="font-mono font-semibold tabular-nums">−{{ $removed }}</b> {{ $removed === 1 ? 'baja' : 'bajas' }} @endif</span>
                @endif
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
@endif
