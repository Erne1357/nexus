{{--
    La «Línea de vigencias» de la ficha de una persona (DESIGN.md, «Personas y puestos»), de core::pages.person:
    el eje de años (a lo más 6 rótulos; uno que chocaría con «Hoy» se omite), la marca «Hoy» con su línea vertical a
    través de los renglones (como la maqueta C) y una barra por nombramiento (AssignmentTimeline). La barra es `bg-primary` si
    está vigente, `bg-primary/30` si terminó y de borde punteado si está programada; en celular el nombre va encima de su barra
    y la línea de hoy solo cruza las barras, no los nombres. Espera `$record`.
--}}
@php($timeline = $record->timeline)

@if ($timeline->hasRange())
    <div data-testid="vigencias">
        <div class="relative grid grid-cols-1 items-center gap-x-3 gap-y-1 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-y-2">
            <span class="max-sm:hidden"></span>
            <div class="relative h-6" aria-hidden="true">
                @foreach ($timeline->years as $year)
                    @php($gap = $timeline->today === null ? 100 : abs($year['left'] - $timeline->today))
                    @if ($gap >= 6)
                        <span @class(['absolute top-0 font-mono text-xs font-medium tabular-nums text-neutral', 'max-sm:hidden' => $gap < 24 || ($loop->count > 4 && $loop->index % 2 === 1)]) style="left: {{ $year['left'] }}%">{{ $year['label'] }}</span>
                    @endif
                @endforeach

                @if ($timeline->today !== null)
                    <span class="badge badge-primary badge-sm absolute top-0 z-10 -translate-x-1/2 font-semibold" style="left: {{ $timeline->today }}%">Hoy</span>
                @endif
            </div>

            @foreach ($timeline->rows as $row)
                <span class="mt-1 text-sm leading-snug sm:mt-0">{{ $row['label'] }}</span>
                <div class="relative h-7 rounded bg-base-200">
                    @foreach ($timeline->years as $year)
                        <span class="absolute inset-y-0 border-s border-base-300" style="left: {{ $year['left'] }}%"></span>
                    @endforeach

                    @if ($timeline->today !== null)
                        <span class="pointer-events-none absolute inset-y-0 z-10 w-0.5 -translate-x-1/2 bg-primary sm:hidden" style="left: {{ $timeline->today }}%" aria-hidden="true"></span>
                    @endif

                    <span
                        role="img"
                        aria-label="{{ $row['label'] }}: {{ mb_strtolower($row['status']->label()) }}{{ $row['open'] ? ', sin fecha de fin' : '' }}"
                        @class([
                            'absolute inset-y-1 min-w-1.5 rounded-sm',
                            'bg-primary' => $row['status'] === \App\Modules\Core\Enums\AssignmentStatus::Current,
                            'bg-primary/30' => $row['status'] === \App\Modules\Core\Enums\AssignmentStatus::Ended,
                            'border border-dashed border-primary bg-primary/10' => $row['status'] === \App\Modules\Core\Enums\AssignmentStatus::Scheduled,
                        ])
                        style="left: {{ $row['left'] }}%; width: {{ $row['width'] }}%"
                    ></span>
                </div>
            @endforeach

            @if ($timeline->today !== null)
                <span
                    data-testid="vigencias-hoy"
                    class="pointer-events-none absolute top-5 bottom-0 z-10 w-0.5 -translate-x-1/2 bg-primary max-sm:hidden"
                    style="left: calc(10.75rem + (100% - 10.75rem) * {{ $timeline->today }} / 100)"
                    aria-hidden="true"
                ></span>
            @endif
        </div>
    </div>
@else
    <p class="text-neutral" data-testid="vigencias-empty">Todavía no tiene nombramientos.</p>
@endif
