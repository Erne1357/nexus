{{--
    El encabezado de la línea de tiempo de las etapas (core::terms.timeline y core::terms.mini-timeline): un carril
    propio para la marca «Hoy» (solo cuando hay lugar para ella), los meses y, debajo, el lunes de cada semana. El
    rótulo de cada mes se acomoda al ancho de su celda (consulta de contenedor): «noviembre 2026», «Nov 2026», «Nov»
    o, si ni eso cabe, nada visible; el nombre completo siempre queda en `title` y para los lectores de pantalla.
    `$cols` son las clases de columnas de la cuadrícula del renglón y `$labelClass`, las de la celda «Etapa» (ambas
    literales en quien las pasa, para que Tailwind las vea); `$flagClass` muestra el carril de «Hoy» solo cuando hay
    lugar para él. El carril mide 22 px: quien dibuje la línea de hoy sobre los renglones la arranca 75 px abajo.
--}}
@props(['timeline', 'cols', 'labelClass', 'flagClass' => 'hidden'])

<div class="relative z-[2] grid border-b border-base-300 bg-secondary/40 {{ $cols }}">
    <div class="{{ $labelClass }}">
        <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Etapa</span>
    </div>

    @if ($timeline->hasRange())
        <div class="relative">
            @if ($timeline->today !== null)
                <div class="relative h-[22px] {{ $flagClass }}">
                    <span @class([
                        'absolute top-[2px] whitespace-nowrap rounded bg-base-content px-[7px] py-px text-[11.5px] font-semibold leading-snug text-base-100',
                        'ms-[3px]' => $timeline->today <= 80,
                        'me-[3px]' => $timeline->today > 80,
                    ]) style="{{ $timeline->today <= 80 ? 'left: '.$timeline->today.'%' : 'right: '.(100 - $timeline->today).'%' }}">Hoy {{ \App\Modules\Core\Support\StageTimeline::dateLabel(today()->toImmutable()) }}</span>
                </div>
            @endif

            <div class="grid h-[26px] items-center" style="grid-template-columns: {{ implode(' ', array_map(fn (array $month): string => $month['days'].'fr', $timeline->months)) }}">
                @foreach ($timeline->months as $month)
                    <span class="@container/month block h-full min-w-0 overflow-hidden border-s border-base-300" title="{{ $month['full'] }}">
                        <span class="flex h-full items-center whitespace-nowrap ps-2 text-[11.5px] font-medium uppercase tracking-[0.09em]">
                            <span class="sr-only">{{ $month['full'] }}</span>
                            <span aria-hidden="true" class="hidden @[2.5rem]/month:inline @[5rem]/month:hidden">{{ $month['short'] }}</span>
                            <span aria-hidden="true" class="hidden @[5rem]/month:inline @[10rem]/month:hidden">{{ $month['label'] }}</span>
                            <span aria-hidden="true" class="hidden @[10rem]/month:inline">{{ $month['full'] }}</span>
                        </span>
                    </span>
                @endforeach
            </div>

            <div class="grid h-[26px] items-center" style="grid-template-columns: repeat({{ count($timeline->weeks) }}, minmax(0, 1fr))">
                @foreach ($timeline->weeks as $week)
                    <span class="flex h-full items-center border-s border-base-300 ps-1.5 font-mono text-[11.5px] text-neutral" title="Semana del {{ $week->format('d/m/Y') }}">{{ $week->day }}</span>
                @endforeach
            </div>
        </div>
    @endif
</div>
