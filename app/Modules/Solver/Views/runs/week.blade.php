{{--
    La semana de la comparación, desde 768 px: la cuadrícula del editor de horarios (scheduling::editor.week, la misma
    geometría de Core\Support\WeekGrid): una columna por día, tan ancha como sus carriles (de al menos 7.25rem), y un
    renglón de 3.25rem por bloque. Lo que no cabe se ve desplazando la caja de lado, nunca la página; al desplazarla, la
    columna de horas y el encabezado de los días (su nombre y cuántas sesiones cambian) se quedan pegados. Cada sesión es
    x-solver::runs.session.
--}}
@php
    $headBg = 'bg-[color-mix(in_oklab,var(--color-secondary)_40%,var(--color-base-100))]';
    $verb = $mode === 'antes' ? ['se mueve', 'se mueven'] : ['cambio', 'cambios'];
@endphp
<div tabindex="0" role="region" aria-label="Semana de {{ $lensChoice['label'] ?? 'la corrida' }}" class="max-h-[calc(100dvh-var(--shell-top)-2rem)] scroll-pt-13 scroll-ps-13 overflow-auto rounded-box border border-base-300 bg-base-100 [scrollbar-color:var(--color-base-300)_transparent] [scrollbar-width:thin] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary max-md:hidden" data-testid="run-week">
    <div class="grid min-w-[calc(3.25rem+var(--units)*var(--lane-min))] grid-cols-[3.25rem_var(--cols)] select-none [--lane-min:7.25rem]"
        style="--cols: {{ implode(' ', array_map(fn (int $count): string => "minmax(calc({$count}*var(--lane-min)),{$count}fr)", $lanes)) }}; --units: {{ array_sum($lanes) }}">
        <div class="{{ $headBg }} sticky top-0 left-0 z-[7] border-e border-b border-base-300"></div>
        @foreach ($grid->days as $index => $day)
            @php($changes = $dayChanges[$day['day']] ?? 0)
            <div @class([$headBg, 'sticky top-0 z-[6] flex min-w-0 flex-col justify-center border-b border-base-300 px-2 py-1.5', 'border-l' => $index > 0])>
                <b class="truncate font-semibold">{{ $day['name'] }}</b>
                <span class="text-xs leading-tight text-neutral">@if ($changes > 0)<span class="font-mono font-semibold text-base-content">{{ $changes }}</span> {{ $changes === 1 ? $verb[0] : $verb[1] }}@else sin cambios @endif</span>
            </div>
        @endforeach

        <div class="sticky left-0 z-[5] border-e border-base-300 bg-base-100" aria-hidden="true">
            @foreach ($grid->blocks as $block)
                <div class="h-13 pe-2 pt-1 text-end font-mono text-xs text-neutral tabular-nums">{{ $block['label'] }}</div>
            @endforeach
        </div>

        @foreach ($grid->days as $index => $day)
            <div @class(['relative min-w-0 border-base-300', 'border-l' => $index > 0]) role="group" aria-label="{{ $day['name'] }}" wire:key="run-day-{{ $day['day'] }}">
                @foreach ($grid->blocks as $h => $block)
                    <div @class(['h-13', 'border-t border-base-300' => $h > 0])></div>
                @endforeach
                @foreach ($laid[$day['day']] ?? [] as $item)
                    <x-solver::runs.session :item="$item" :chain-classes="$chainClasses" />
                @endforeach
            </div>
        @endforeach
    </div>
</div>
