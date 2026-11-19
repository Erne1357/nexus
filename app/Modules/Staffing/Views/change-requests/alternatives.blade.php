{{--
    «Impacto y alternativas» en el detalle de una solicitud pendiente (tarea 8.7, maqueta A «Pestañas», elegida por el
    usuario el 18 de noviembre de 2026; DESIGN.md, «Solicitudes de cambio»). Lo pedido y lo que regresa el motor son
    versiones del mismo cambio: una pestaña «Lo pedido» (el impacto de CR-01) y una por alternativa (CR-02), cada una
    con su veredicto en la cabecera (choques duros, o el puntaje; «Vencida» si algo cambió). A la derecha de las
    pestañas, para el jefe, el lugar de la búsqueda: «Buscar alternativas» (deshabilitado con su motivo si lo pedido no
    deja choques duros), «Buscando…» mientras el motor corre (`wire:loading`; si el motor corre en la cola, la pantalla
    pregunta cada 2 s con `wire:poll`, solo entonces) o «Sin alternativa». Debajo: «Esto cambió…» con «Buscar otra
    vez», lo que dijo el motor, y el panel de la pestaña abierta (staffing::change-requests.alternative-panel). El pie
    del detalle aprueba esa pestaña. Las flechas, Inicio y Fin recorren las pestañas (una sola parada de tabulación).
    Lo arma ChangeAlternativesQuery; recibe `$alternatives`, `$request`, `$canDecide` y `$staleAlternatives`.
--}}
@php
    $status = $request->alternatives['status'] ?? null;
    $computing = $status === 'computing';
    $failed = $status === 'failed';
    $items = $alternatives['items'];
    $selectedKey = $alternatives['selected']['key'] ?? 'pedido';
    $stale = $staleAlternatives && $items > 0;
    $canSearch = $canDecide && $alternatives['assessable'] && ! $computing;
    $movingText = 'mueve solo '.$request->group->subject?->code.' · '.$request->group->code.' y los grupos de su departamento con los que choca; lo demás queda fijo';
@endphp
<section aria-labelledby="request-impact-title" data-testid="request-impact" class="min-w-0">
    <div class="mb-2 flex items-center justify-between gap-2">
        <h3 id="request-impact-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Impacto y alternativas</h3>
        @if ($canDecide)
            <x-button label="Recalcular impacto" icon="o-arrow-path" class="btn-ghost btn-sm max-md:h-11 max-md:min-h-11" wire:click="recalculateImpact" spinner="recalculateImpact" data-testid="recalculate-impact" />
        @endif
    </div>

    @if ($request->impact === null)
        <p class="text-sm text-neutral" data-testid="impact-none">Todavía no se calcula.</p>
    @elseif (! $alternatives['assessable'])
        <p class="text-sm text-neutral" data-testid="impact-unassessable">La propuesta no trae un horario o un salón concretos: no se pudo simular.</p>
    @else
        <div class="space-y-3">
            @if ($stale)
                <div class="alert alert-warning alert-soft items-start text-sm max-md:grid-cols-1" role="alert" data-testid="alternatives-stale">
                    <x-icon name="o-exclamation-triangle" class="h-5 w-5 shrink-0" aria-hidden="true" />
                    <div class="min-w-0">
                        <p class="font-semibold">Esto cambió desde que se calcularon las alternativas.</p>
                        <p>El horario de esos grupos o lo que los rodea ya no es el mismo; las alternativas son de {{ $alternatives['computedAt']?->isToday() ? 'hoy a las '.$alternatives['computedAt']->format('G:i') : 'antes' }}. Vuelve a buscar para elegir con el horario de hoy.</p>
                    </div>
                    @if ($canSearch)
                        <x-button label="Buscar otra vez" icon="o-arrow-path" class="btn-primary btn-sm max-md:h-11 max-md:min-h-11" wire:click="searchAlternatives" spinner="searchAlternatives" data-testid="search-again" />
                    @endif
                </div>
            @endif

            <div class="@container min-w-0">
                <div class="flex min-w-0 items-stretch gap-2 border-b border-base-300 @max-[26rem]:flex-wrap @max-[26rem]:gap-x-0">
                    <div class="grid min-w-0 flex-1 auto-cols-[minmax(0,1fr)] grid-flow-col @max-[26rem]:basis-full" role="tablist" aria-label="Versiones del cambio" data-testid="alternatives-tabs"
                        x-on:keydown="
                            const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End']
                            if (! keys.includes($event.key)) { return }
                            const tabs = [...$el.querySelectorAll('[role=tab]')]
                            const i = tabs.indexOf(document.activeElement)
                            const j = $event.key === 'Home' ? 0 : $event.key === 'End' ? tabs.length - 1 : (i + ($event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length
                            $event.preventDefault()
                            tabs[j].focus()
                            tabs[j].click()
                        ">
                        @foreach ($alternatives['tabs'] as $tab)
                            @php
                                $on = $tab['key'] === $selectedKey;
                                $tabStale = $stale && $tab['letter'] !== null;
                            @endphp
                            <button type="button" role="tab" id="tab-{{ $tab['key'] }}" aria-controls="alternative-panel" aria-selected="{{ $on ? 'true' : 'false' }}" tabindex="{{ $on ? 0 : -1 }}"
                                wire:click="$set('alternative', '{{ $tab['key'] }}')" data-testid="tab-{{ $tab['key'] }}"
                                @class([
                                    '-mb-px grid min-w-0 content-center gap-0.5 border-b-2 px-3 pt-2.5 pb-2 text-start hover:bg-base-200 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary @max-[30rem]:px-2 max-md:min-h-11',
                                    'border-primary bg-[color-mix(in_oklab,var(--color-secondary)_35%,var(--color-base-100))]' => $on,
                                    'border-transparent' => ! $on,
                                ])>
                                <span @class(['truncate text-sm whitespace-nowrap', 'font-semibold' => $on, 'font-medium' => ! $on])>
                                    <span class="@max-[30rem]:hidden">{{ $tab['name'] }}</span><span class="hidden @max-[30rem]:inline">{{ $tab['short'] }}</span>
                                </span>
                                <span @class(['flex items-center gap-1 overflow-hidden text-[12.5px] whitespace-nowrap', 'text-error' => ! $tabStale && $tab['hard'] > 0, 'text-neutral' => $tabStale || $tab['hard'] === 0])>
                                    @if ($tabStale)
                                        <x-icon name="o-clock" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Vencida
                                    @elseif ($tab['hard'] > 0)
                                        <x-icon name="o-x-circle" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span class="font-mono font-semibold tabular-nums">{{ $tab['hard'] }}</span>
                                        <span class="@max-[30rem]:hidden">{{ $tab['hard'] === 1 ? 'choque' : 'choques' }}</span><span class="hidden @max-[30rem]:inline">{{ $tab['hard'] === 1 ? 'duro' : 'duros' }}</span>
                                    @elseif ($tab['score'] !== null)
                                        <x-icon name="o-check-circle" class="h-3.5 w-3.5 shrink-0 text-success" aria-hidden="true" /><span class="@max-[30rem]:hidden">Puntaje</span><span class="hidden @max-[30rem]:inline">Pts.</span>
                                        <span class="font-mono font-semibold tabular-nums">{{ $tab['score'] < 0 ? '−'.abs($tab['score']) : $tab['score'] }}</span>
                                    @else
                                        <x-icon name="o-check-circle" class="h-3.5 w-3.5 shrink-0 text-success" aria-hidden="true" /><span class="@max-[30rem]:hidden">Sin choques duros</span><span class="hidden @max-[30rem]:inline">Sin duros</span>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </div>

                    @php
                        $slot = '-mb-px grid min-w-0 shrink content-center gap-0.5 rounded-t-field border border-b-0 border-dashed px-3 pt-2.5 pb-2 text-start @max-[30rem]:px-2 @max-[26rem]:order-first @max-[26rem]:mb-2 @max-[26rem]:basis-full @max-[26rem]:rounded-field @max-[26rem]:border-b max-md:min-h-11';
                        $busy = $slot.' border-info/45 bg-info/10 text-info';
                    @endphp
                    @if ($computing)
                        <div class="{{ $busy }}" wire:poll.2s="pollAlternatives" aria-hidden="true" data-testid="alternatives-computing-slot">
                            <span class="flex items-center gap-1.5 text-sm font-semibold whitespace-nowrap"><x-icon name="o-arrow-path" class="h-4 w-4 shrink-0 motion-safe:animate-spin" />Buscando…</span>
                            <span class="truncate text-[12.5px] @max-[30rem]:hidden @max-[26rem]:block">El motor está buscando</span>
                        </div>
                    @elseif ($items === 0 && $alternatives['searched'] && ! $failed)
                        <div class="{{ $slot }} border-base-content/25 bg-base-100 text-neutral" data-testid="alternatives-none-slot">
                            <span class="flex items-center gap-1.5 text-sm font-semibold whitespace-nowrap"><x-icon name="o-no-symbol" class="h-4 w-4 shrink-0" aria-hidden="true" />Sin alternativa</span>
                            <span class="truncate text-[12.5px] @max-[30rem]:hidden @max-[26rem]:block">Ninguna sin choques duros</span>
                        </div>
                    @elseif ($items === 0 && $canSearch && $alternatives['pedidoHard'] > 0)
                        <button type="button" wire:click="searchAlternatives" wire:loading.remove wire:target="searchAlternatives" data-testid="search-alternatives"
                            class="{{ $slot }} border-base-content/35 bg-base-100 text-primary hover:border-primary hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                            <span class="flex items-center gap-1.5 text-sm font-semibold whitespace-nowrap"><x-icon name="o-arrows-right-left" class="h-4 w-4 shrink-0" aria-hidden="true" />Buscar alternativas</span>
                            <span class="truncate text-[12.5px] text-neutral @max-[30rem]:hidden @max-[26rem]:block">2 o 3 acomodos sin choques duros</span>
                        </button>
                        <div class="{{ $busy }} hidden" wire:loading.grid wire:target="searchAlternatives" aria-hidden="true">
                            <span class="flex items-center gap-1.5 text-sm font-semibold whitespace-nowrap"><x-icon name="o-arrow-path" class="h-4 w-4 shrink-0 motion-safe:animate-spin" />Buscando…</span>
                            <span class="truncate text-[12.5px] @max-[30rem]:hidden @max-[26rem]:block">Tarda unos segundos</span>
                        </div>
                    @elseif ($items === 0 && $canSearch)
                        <div class="{{ $slot }} border-base-content/25 bg-base-100 text-neutral" aria-disabled="true" title="Lo pedido no deja choques duros: se aprueba como se pidió." data-testid="search-alternatives-disabled">
                            <span class="flex items-center gap-1.5 text-sm font-semibold whitespace-nowrap"><x-icon name="o-arrows-right-left" class="h-4 w-4 shrink-0" aria-hidden="true" />Buscar alternativas</span>
                            <span class="truncate text-[12.5px] @max-[30rem]:hidden @max-[26rem]:block">Lo pedido no deja choques duros</span>
                        </div>
                    @endif
                </div>
            </div>

            @if ($computing)
                <div class="grid gap-2 rounded-field border border-info/45 bg-info/10 px-3.5 py-3" role="status" aria-live="polite" data-testid="alternatives-computing">
                    <p class="flex items-center gap-2 font-semibold"><x-icon name="o-arrow-path" class="h-4 w-4 shrink-0 text-info motion-safe:animate-spin" aria-hidden="true" />Buscando alternativas…</p>
                    <progress class="progress progress-info h-1.5 w-full" aria-hidden="true"></progress>
                    <p class="text-[13px] text-neutral">El motor {{ $movingText }}. La página se actualiza sola al terminar.</p>
                </div>
            @elseif ($items === 0 && $canSearch && $alternatives['pedidoHard'] > 0)
                <div class="hidden gap-2 rounded-field border border-info/45 bg-info/10 px-3.5 py-3" wire:loading.grid wire:target="searchAlternatives" role="status" aria-live="polite" data-testid="alternatives-searching">
                    <p class="flex items-center gap-2 font-semibold"><x-icon name="o-arrow-path" class="h-4 w-4 shrink-0 text-info motion-safe:animate-spin" aria-hidden="true" />Buscando alternativas…</p>
                    <progress class="progress progress-info h-1.5 w-full" aria-hidden="true"></progress>
                    <p class="text-[13px] text-neutral">El motor {{ $movingText }}. Tarda unos segundos.</p>
                </div>
            @endif

            @if ($failed)
                <div class="alert alert-error alert-soft items-start text-sm max-md:grid-cols-1" role="alert" data-testid="alternatives-failed">
                    <x-icon name="o-x-circle" class="h-5 w-5 shrink-0" aria-hidden="true" />
                    <div class="min-w-0">
                        <p class="font-semibold">El motor no pudo buscar alternativas.</p>
                        <p class="break-words">{{ $request->alternatives['message'] ?? $request->alternatives['reason'] ?? 'Algo falló al correrlo.' }}</p>
                    </div>
                    @if ($canSearch)
                        <x-button label="Buscar otra vez" icon="o-arrow-path" class="btn-sm max-md:h-11 max-md:min-h-11" wire:click="searchAlternatives" spinner="searchAlternatives" />
                    @endif
                </div>
            @elseif ($items === 0 && $alternatives['searched'] && ! $computing)
                <div class="flex items-start gap-3 rounded-field border border-base-300 bg-base-200/60 px-3.5 py-3 text-sm" role="status" data-testid="alternatives-none">
                    <x-icon name="o-no-symbol" class="mt-0.5 h-5 w-5 shrink-0 text-neutral" aria-hidden="true" />
                    <div class="grid min-w-0 gap-1">
                        <p class="font-semibold">El motor no encontró un acomodo sin choques duros.</p>
                        <p>Buscó {{ $alternatives['computedAt']?->isToday() ? 'hoy a las '.$alternatives['computedAt']->format('G:i') : '' }} y {{ $movingText }}.</p>
                        <p class="text-neutral">Puedes rechazarla con un comentario para que pidan otro horario, o liberar el lugar en el editor de horarios y volver a buscar.</p>
                        @if ($canSearch)
                            <div class="mt-1 flex flex-wrap gap-2">
                                <x-button label="Buscar otra vez" icon="o-arrow-path" class="btn-sm max-md:h-11 max-md:min-h-11" wire:click="searchAlternatives" spinner="searchAlternatives" data-testid="search-again" />
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if ($items > 0 && $alternatives['engineLine'])
                <p class="text-[13px] text-neutral" data-testid="alternatives-engine">
                    {{ $alternatives['engineLine'] }}
                    @if ($canSearch && ! $stale)
                        <button type="button" class="link link-primary ms-1 font-medium whitespace-nowrap" wire:click="searchAlternatives" wire:loading.attr="disabled" wire:target="searchAlternatives" data-testid="search-again-quiet">Buscar otra vez</button>
                    @endif
                </p>
            @endif

            @if ($items > 0 && $alternatives['pedidoHard'] === 0 && ! $stale)
                <p class="flex items-start gap-2 rounded-field border border-base-300 bg-base-200/60 px-3.5 py-2.5 text-sm" data-testid="alternatives-not-better">
                    <x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 shrink-0 text-neutral" aria-hidden="true" />
                    <span>Ninguna alternativa mejora lo pedido: lo pedido ya no deja choques duros, así que se puede aprobar como se pidió.</span>
                </p>
            @endif

            @if ($alternatives['selected'])
                <div wire:loading.class="opacity-60" wire:target="alternative">
                    @include('staffing::change-requests.alternative-panel', ['option' => $alternatives['selected'], 'stale' => $stale, 'tabbed' => true])
                </div>
            @endif
        </div>
    @endif
</section>
