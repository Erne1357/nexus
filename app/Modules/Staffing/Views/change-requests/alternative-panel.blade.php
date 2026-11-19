{{--
    El panel de una opción de «Impacto y alternativas» (tarea 8.7, maqueta A): su veredicto (choques duros o «Sin choques
    duros» con su puntaje) y qué tan cerca queda de lo pedido; los choques duros de lo pedido por regla, con los grupos
    afectados; la cadena de movimientos (el grupo de la solicitud primero y luego los que se mueven para hacerle lugar,
    cada uno con sus sesiones de antes, tachadas, a después); los avisos que deja; la semana del alumno tipo con la
    opción puesta y los docentes que cambian. Vencida («Esto cambió…»), todo menos el veredicto se atenúa.
    Recibe `$option` (ChangeAlternativesQuery) y `$stale`; `$tabbed` lo hace el panel de las pestañas.
--}}
@php
    $isPedido = $option['kind'] === \App\Modules\Staffing\Queries\ChangeAlternativesQuery::PEDIDO;
    $chains = \App\Modules\Core\Support\WeekGrid::CHAINS;
    $dim = $stale ? 'opacity-60' : '';
    $cap = 'text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral';
@endphp
<div class="grid min-w-0 gap-[18px] pt-1.5 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
    @if ($tabbed) role="tabpanel" id="alternative-panel" aria-labelledby="tab-{{ $option['key'] }}" tabindex="0" @endif
    data-testid="alternative-panel" data-option="{{ $option['key'] }}">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        @if ($stale && ! $isPedido)
            <x-core::status-badge tone="draft" icon="o-clock" label="Vencida" />
        @elseif ($option['hardCount'] > 0)
            <x-core::status-badge tone="error" :label="$option['hardCount'].' '.($option['hardCount'] === 1 ? 'choque duro' : 'choques duros')" data-testid="impact-hard-count" />
        @else
            <x-core::status-badge tone="success" label="Sin choques duros" data-testid="impact-clear" />
        @endif
        @if ($option['score'] !== null)
            <span class="inline-flex items-baseline gap-1.5 whitespace-nowrap" title="Menos es mejor: los puntos de avisos que agrega al horario de hoy">
                <b class="font-mono text-[15px] font-semibold tabular-nums">{{ $option['score'] < 0 ? '−'.abs($option['score']) : $option['score'] }}</b><span class="text-[13px] text-neutral">puntaje</span>
            </span>
        @endif
        @if ($option['near'])
            <span class="inline-flex min-w-0 items-start gap-1.5 text-sm">
                <x-icon :name="$option['near']['same'] ? 'o-check' : 'o-arrows-right-left'" @class(['mt-0.5 h-4 w-4 shrink-0', 'text-success' => $option['near']['same'], 'text-neutral' => ! $option['near']['same']]) aria-hidden="true" />
                <span class="min-w-0 break-words">{{ $option['near']['text'] }}@if ($option['near']['note'])<span class="text-neutral"> · {{ $option['near']['note'] }}</span>@endif</span>
            </span>
        @endif
    </div>

    @if ($option['hard'] !== [])
        <div class="grid min-w-0 gap-2 {{ $dim }}">
            <h4 class="{{ $cap }}">Choques duros</h4>
            <ul class="space-y-2" data-testid="impact-hard">
                @foreach ($option['hard'] as $rule)
                    <li class="rounded-field border border-error/40 bg-error/10 px-3.5 py-3" data-testid="impact-rule">
                        <div class="flex items-start gap-2">
                            <span class="badge badge-error badge-soft h-6.5 shrink-0 font-mono font-medium">{{ $rule['rule'] }}</span>
                            <div class="min-w-0 space-y-0.5 text-sm">
                                @foreach ($rule['messages'] as $message)
                                    <p class="break-words">{{ $message }}</p>
                                @endforeach
                            </div>
                        </div>
                        @if ($rule['groupChips'] !== [])
                            <ul class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($rule['groupChips'] as $other)
                                    <li class="badge badge-neutral badge-soft h-auto min-h-6.5 max-w-full gap-1.5 py-0.5 text-start font-medium"><span class="font-mono">{{ $other['code'] }}</span> {{ $other['subject'] }}@if ($other['teacher']) <span class="text-neutral">· {{ $other['teacher'] }}</span>@endif</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid min-w-0 gap-2 {{ $dim }}">
        <h4 class="{{ $cap }}">{{ $isPedido ? 'Lo que se mueve' : 'Cadena de movimientos' }}</h4>
        <ol class="grid gap-3.5" data-testid="alternative-chain">
            @foreach ($option['chain'] as $row)
                <li class="{{ \Illuminate\Support\Arr::toCssClasses(['relative min-w-0 ps-[22px]', $row['color'] === null ? '[--chain:var(--color-neutral)]' : $chains[$row['color'] % count($chains)]]) }}">
                    <span class="absolute start-0 top-1 h-3 w-3 rounded-[4px] border-[1.5px] border-(--chain) bg-[color-mix(in_oklab,var(--chain)_18%,var(--color-base-100))]" aria-hidden="true"></span>
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-sm">
                        <span class="font-mono font-semibold whitespace-nowrap [word-spacing:-0.25em]">{{ $row['label'] }}</span>
                        <span>{{ $row['subject'] }}</span>
                        @if ($row['teacher'])
                            <span class="text-neutral">· {{ $row['teacher'] }}</span>
                        @endif
                    </div>
                    <p @class(['text-[12.5px]', 'text-info' => $row['requested'], 'text-warning' => ! $row['requested']])>{{ $row['requested'] ? 'El grupo de la solicitud' : 'Se mueve para hacerle lugar' }}</p>
                    @foreach ($row['moves'] as $move)
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-[13px] leading-normal [word-spacing:-0.25em]">
                            <span @class(['min-w-[4.2rem] font-sans text-[11.5px] font-semibold [word-spacing:normal]', 'text-warning' => $move['tone'] === 'warning', 'text-error' => $move['tone'] === 'error', 'text-info' => $move['tone'] === 'info', 'text-neutral' => $move['tone'] === 'neutral'])>{{ $move['tag'] }}</span>
                            @if ($move['was'])
                                <span class="text-neutral line-through decoration-neutral/60"><span class="sr-only">antes </span>{{ $move['was'] }}</span>
                            @endif
                            @if ($move['was'] && $move['now'])
                                <x-icon name="o-arrow-right" class="h-3.5 w-3.5 shrink-0 text-neutral" aria-hidden="true" />
                            @endif
                            @if ($move['now'])
                                <span class="font-semibold"><span class="sr-only">después </span>{{ $move['now'] }}</span>
                            @endif
                        </div>
                    @endforeach
                    @if ($row['same'])
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-[13px] leading-normal [word-spacing:-0.25em]">
                            <span class="min-w-[4.2rem] font-sans text-[11.5px] font-medium text-neutral [word-spacing:normal]">Sin cambio</span>
                            <span>{{ $row['same'] }}</span>
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>

    @if (! ($option['hard'] !== [] && $option['soft'] === []))
        <div class="grid min-w-0 gap-2 {{ $dim }}">
            <h4 class="{{ $cap }}">Avisos que deja</h4>
            @if ($option['soft'] === [])
                <p class="flex items-center gap-1.5 text-sm text-neutral"><x-icon name="o-check-circle" class="h-4 w-4 text-success" aria-hidden="true" />Sin avisos.</p>
            @else
                <ul class="grid gap-1.5" data-testid="impact-soft">
                    @foreach ($option['soft'] as $soft)
                        <li class="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-2.5 text-sm"><span class="font-mono text-neutral">{{ $soft['rule'] }}</span><span class="break-words">{{ $soft['message'] }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($option['inputProblems'] !== [])
        <div class="grid min-w-0 gap-2 {{ $dim }}" data-testid="alternative-previous-problems">
            <h4 class="{{ $cap }}">Ya estaba</h4>
            <ul class="grid gap-1.5">
                @foreach ($option['inputProblems'] as $problem)
                    <li class="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-2.5 text-sm">
                        <span class="font-mono text-neutral">{{ $problem['rule'] }}</span>
                        <span class="min-w-0 break-words">{{ $problem['message'] }} <span class="badge badge-neutral badge-soft badge-sm ms-1 whitespace-nowrap">{{ $problem['why'] }}</span></span>
                    </li>
                @endforeach
            </ul>
            <p class="text-[12.5px] text-neutral">Estos choques no los causa la alternativa: ya los tenían estos grupos antes del cambio, o no dependen de su horario.</p>
        </div>
    @endif

    <div class="grid min-w-0 gap-2 {{ $dim }}">
        <h4 class="{{ $cap }}">Semana de {{ $option['week']['label'] }}</h4>
        @include('staffing::change-requests.alternative-week', ['week' => $option['week'], 'name' => $option['name']])
    </div>

    @if ($option['teachers'] !== [])
        <p class="text-sm text-neutral {{ $dim }}" data-testid="impact-teachers">Docentes que cambian:
            @foreach ($option['teachers'] as $teacher)
                <span class="font-medium text-base-content">{{ $teacher['name'] }}</span>@if ($teacher['asked']) (pidió el cambio)@endif{{ $loop->last ? '' : ' ·' }}
            @endforeach
        </p>
    @endif
</div>
