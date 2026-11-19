{{--
    La semana del alumno tipo con una opción puesta (tarea 8.7, maqueta A): la cuadrícula del editor en pequeño
    (Core\Support\WeekGrid, renglones de 30 px; 22 px en una caja de menos de 30rem) con el lenguaje de «Corridas del
    motor». Cada sesión lleva la franja de su materia (`--chain`) y su estado con borde y marca, nunca solo color:
    movida (borde `warning` y de dónde viene, «↩ Ma 7:00»), nueva (borde `info`, «Nueva»), choque duro de lo pedido
    (borde `error`, «Choca»), sin cambio (sin tinte, franja al 40 %) y fija de otro departamento (`base-200` punteado,
    «Fija · CB»). En una caja angosta los bloques solo llevan su color y su marca, y debajo va la lista de lo que cambia.
    Solo las horas que ocupa alguna sesión. Lo arma ChangeAlternativesQuery::week().
--}}
@php
    $chains = \App\Modules\Core\Support\WeekGrid::CHAINS;
    $rows = count($week['blocks']);
    $days = count($week['days']);
@endphp
<div class="@container min-w-0" data-testid="alternative-week">
    <div class="grid overflow-hidden rounded-field border border-base-300 bg-base-100 [--r:30px] @max-[30rem]:[--r:22px] grid-cols-[2.6rem_repeat(var(--days),minmax(0,1fr))] @max-[30rem]:grid-cols-[2.2rem_repeat(var(--days),minmax(0,1fr))]"
        style="--days: {{ $days }}; --rows: {{ $rows }}" role="img" aria-label="Semana de {{ $week['label'] }} con {{ mb_strtolower($name) }}">
        <div class="border-e border-b border-base-300 bg-[color-mix(in_oklab,var(--color-secondary)_40%,var(--color-base-100))]"></div>
        @foreach ($week['days'] as $index => $day)
            <div class="{{ \Illuminate\Support\Arr::toCssClasses(['truncate border-b border-base-300 bg-[color-mix(in_oklab,var(--color-secondary)_40%,var(--color-base-100))] px-1.5 py-1 text-xs font-semibold', 'border-s' => $index > 0]) }}">
                <span class="@max-[30rem]:hidden">{{ $day['name'] }}</span><span class="hidden @max-[30rem]:inline">{{ \App\Modules\Staffing\Support\SessionLabels::DAYS[$day['day']] ?? $day['short'] }}</span>
            </div>
        @endforeach
        <div class="border-e border-base-300">
            @foreach ($week['blocks'] as $block)
                <div class="h-(--r) pe-1.5 pt-0.5 text-end font-mono text-[11px] leading-tight text-neutral tabular-nums">{{ $block['label'] }}</div>
            @endforeach
        </div>
        @foreach ($week['days'] as $index => $day)
            <div class="{{ \Illuminate\Support\Arr::toCssClasses(['relative h-[calc(var(--rows)*var(--r))] bg-[image:repeating-linear-gradient(to_bottom,transparent_0_calc(var(--r)-1px),var(--color-base-300)_calc(var(--r)-1px)_var(--r))]', 'border-s border-base-300' => $index > 0]) }}">
                @foreach ($week['laid'][$day['day']] ?? [] as $item)
                    @php
                        $state = $item['state'];
                        $mark = match ($state) {
                            'moved' => ['icon' => 'o-arrow-uturn-left', 'text' => $item['prev'], 'tone' => 'border-warning/35 bg-warning/10 text-warning', 'mono' => true],
                            'new' => ['icon' => 'o-plus', 'text' => 'Nueva', 'tone' => 'border-info/35 bg-info/10 text-info', 'mono' => false],
                            'hard' => ['icon' => 'o-x-circle', 'text' => 'Choca', 'tone' => 'border-error/35 bg-error/10 text-error', 'mono' => false],
                            default => null,
                        };
                        $changed = in_array($state, ['moved', 'new', 'hard'], true);
                        $quiet = in_array($state, ['same', 'fixed'], true);
                        $label = "{$item['label']}, {$day['name']} {$item['hours']}".($item['room'] ? ", {$item['room']}" : '').match ($state) {
                            'moved' => ", movida desde {$item['prev']}",
                            'new' => ', nueva',
                            'hard' => ', choca',
                            'fixed' => ', fija de otro departamento',
                            default => ', sin cambio',
                        };
                    @endphp
                    <div class="{{ \Illuminate\Support\Arr::toCssClasses([
                        $item['color'] === null ? '[--chain:var(--color-neutral)]' : $chains[$item['color'] % count($chains)],
                        $item['span'] > 1 ? 'py-0.5' : 'py-0',
                        'absolute z-[2] flex flex-col overflow-hidden rounded-[4px] ps-[7px] pe-1 top-[calc(var(--top)*var(--r)+1px)] left-[calc(var(--lane)*100%/var(--lanes)+2px)] h-[calc(var(--span)*var(--r)-2px)] w-[calc(100%/var(--lanes)-4px)] @max-[30rem]:ps-[5px] @max-[30rem]:pe-0.5',
                        match ($state) {
                            'moved' => 'border-[1.5px] border-warning bg-[color-mix(in_oklab,var(--chain)_9%,var(--color-base-100))] shadow-[inset_3px_0_0_var(--chain)]',
                            'new' => 'border-[1.5px] border-info bg-[color-mix(in_oklab,var(--chain)_9%,var(--color-base-100))] shadow-[inset_3px_0_0_var(--chain)]',
                            'hard' => 'border-2 border-error bg-error/7',
                            'fixed' => 'border border-dashed border-base-content/30 bg-base-200 text-neutral shadow-[inset_3px_0_0_color-mix(in_oklab,var(--color-neutral)_45%,var(--color-base-200))]',
                            default => 'border border-base-300 bg-base-100 text-neutral shadow-[inset_3px_0_0_color-mix(in_oklab,var(--chain)_40%,var(--color-base-100))]',
                        },
                    ]) }}" style="--top: {{ $item['top'] }}; --span: {{ $item['span'] }}; --lane: {{ $item['lane'] }}; --lanes: {{ $item['lanes'] }}" title="{{ $label }}" data-state="{{ $state }}">
                        <span @class(['truncate font-mono text-[11px] text-base-content [word-spacing:-0.3em] @max-[30rem]:hidden', 'leading-tight' => $item['span'] > 1, 'leading-none pt-px' => $item['span'] <= 1, 'font-semibold' => $changed, 'font-medium' => ! $changed])>{{ $item['title'] }}</span>
                        @if ($item['span'] > 1 || $quiet)
                            <span class="truncate font-mono text-[11px] leading-tight text-neutral @max-[30rem]:hidden">{{ $state === 'fixed' ? $item['mark'] : ($item['room'] ?? 'Sin salón') }}</span>
                        @endif
                        @if ($mark)
                            <span @class(['flex min-w-0', 'mt-px' => $item['span'] > 1])>
                                <span class="inline-flex h-[14px] max-w-full items-center gap-px overflow-hidden rounded-[3px] border px-0.5 text-[11px] leading-none font-semibold whitespace-nowrap {{ $mark['tone'] }}">
                                    <x-icon :name="$mark['icon']" class="h-[11px] w-[11px] shrink-0" />
                                    <span @class(['truncate @max-[30rem]:hidden', 'font-mono' => $mark['mono']])>{{ $mark['text'] }}</span>
                                </span>
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    @if ($week['changed'] !== [])
        {{-- En una caja angosta, la lista de lo que cambia se ve debajo; en una ancha queda para los lectores de pantalla,
             porque los bloques solo lo dicen en `title`. --}}
        <ul class="sr-only mt-2 gap-1.5 @max-[30rem]:not-sr-only @max-[30rem]:grid" data-testid="alternative-week-list">
            @foreach ($week['changed'] as $item)
                <li class="flex flex-wrap gap-x-2 gap-y-0.5 font-mono text-[13px] [word-spacing:-0.25em]">
                    <span @class(['font-sans text-xs font-semibold [word-spacing:normal]', 'text-error' => $item['state'] === 'hard', 'text-info' => $item['state'] === 'new', 'text-warning' => $item['state'] === 'moved'])>{{ match ($item['state']) { 'hard' => 'Choca', 'new' => 'Nueva', default => 'Movida' } }}</span>
                    <span>{{ $item['label'] }}</span>
                    <span>{{ \App\Modules\Staffing\Support\SessionLabels::DAYS[$item['day']] ?? $item['day'] }} {{ $item['hours'] }}@if ($item['room']) · {{ $item['room'] }}@endif</span>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-2 flex flex-wrap gap-x-3.5 gap-y-1 text-[12.5px] text-neutral" aria-hidden="true">
        @if ($week['hasMoved'])
            <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-5 shrink-0 rounded-[4px] border-[1.5px] border-warning bg-warning/10"></span>Movida <x-icon name="o-arrow-uturn-left" class="h-3 w-3" /> de dónde viene</span>
        @endif
        @if ($week['hasNew'])
            <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-5 shrink-0 rounded-[4px] border-[1.5px] border-info bg-info/10"></span>Nueva</span>
        @endif
        @if ($week['hasHard'])
            <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-5 shrink-0 rounded-[4px] border-2 border-error"></span>Choque duro</span>
        @endif
        <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-5 shrink-0 rounded-[4px] border border-base-300 bg-base-100 shadow-[inset_3px_0_0_color-mix(in_oklab,var(--color-neutral)_45%,var(--color-base-100))]"></span>Sin cambio</span>
        @if ($week['hasFixed'])
            <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-5 shrink-0 rounded-[4px] border border-dashed border-base-content/30 bg-base-200"></span>Fija (otro departamento)</span>
        @endif
    </div>
</div>
