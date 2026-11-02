{{--
    El encabezado de la línea de tiempo de las etapas (core::terms.timeline y core::terms.mini-timeline): los meses
    y, debajo, el lunes de cada semana, con la marca «Hoy». `$cols` son las clases de columnas de la cuadrícula del
    renglón y `$labelClass`, las de la celda «Etapa» (ambas literales en quien las pasa, para que Tailwind las vea);
    `$flagClass` muestra la marca «Hoy» solo cuando hay lugar para ella.
--}}
@props(['timeline', 'cols', 'labelClass', 'flagClass' => 'hidden'])

<div class="relative z-[2] grid h-[52px] border-b border-base-300 bg-secondary/40 {{ $cols }}">
    <div class="{{ $labelClass }}">
        <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Etapa</span>
    </div>

    @if ($timeline->hasRange())
        <div class="relative">
            <div class="grid h-[26px] items-center" style="grid-template-columns: {{ implode(' ', array_map(fn (array $month): string => $month['days'].'fr', $timeline->months)) }}">
                @foreach ($timeline->months as $month)
                    <span class="flex h-full items-center overflow-hidden whitespace-nowrap border-s border-base-300 ps-2 text-[11.5px] font-medium uppercase tracking-[0.09em]">{{ $month['label'] }}</span>
                @endforeach
            </div>

            <div class="grid h-[26px] items-center" style="grid-template-columns: repeat({{ count($timeline->weeks) }}, minmax(0, 1fr))">
                @foreach ($timeline->weeks as $week)
                    <span class="flex h-full items-center border-s border-base-300 ps-1.5 font-mono text-[11.5px] text-neutral" title="Semana del {{ $week->format('d/m/Y') }}">{{ $week->day }}</span>
                @endforeach
            </div>

            @if ($timeline->today !== null)
                <span class="absolute top-[3px] z-[3] ms-[3px] whitespace-nowrap rounded bg-base-content px-[7px] py-px text-[11.5px] font-semibold leading-snug text-base-100 {{ $flagClass }}" style="left: {{ $timeline->today }}%">Hoy {{ \App\Modules\Core\Support\StageTimeline::dateLabel(today()->toImmutable()) }}</span>
            @endif
        </div>
    @endif
</div>
