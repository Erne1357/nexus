{{--
    La «Línea de vigencias» de la ficha de una persona (DESIGN.md, «Personas y puestos»), de core::pages.person:
    el eje de años, la marca «Hoy» y una barra por nombramiento (AssignmentTimeline). La barra es `bg-primary` si
    está vigente, `bg-primary/30` si terminó y de borde punteado si está programada; con la caja angosta se desplaza
    de lado. Espera `$record`.
--}}
@php($timeline = $record->timeline)

@if ($timeline->hasRange())
    <div class="overflow-x-auto" data-testid="vigencias">
        <div class="grid min-w-[34rem] grid-cols-[10rem_minmax(0,1fr)] items-center gap-x-3 gap-y-2">
            <span></span>
            <div class="relative h-6" aria-hidden="true">
                @foreach ($timeline->years as $year)
                    <span class="absolute top-0 font-mono text-xs font-medium tabular-nums text-neutral" style="left: {{ $year['left'] }}%">{{ $year['label'] }}</span>
                @endforeach

                @if ($timeline->today !== null)
                    <span class="badge badge-primary badge-sm absolute top-0 z-10 -translate-x-1/2 font-semibold" style="left: {{ $timeline->today }}%">Hoy</span>
                @endif
            </div>

            @foreach ($timeline->rows as $row)
                <span class="text-sm leading-snug">{{ $row['label'] }}</span>
                <div class="relative h-6 rounded bg-base-200">
                    @foreach ($timeline->years as $year)
                        <span class="absolute inset-y-0 border-s border-base-300" style="left: {{ $year['left'] }}%"></span>
                    @endforeach

                    <span
                        role="img"
                        aria-label="{{ $row['label'] }}: {{ mb_strtolower($row['status']->label()) }}{{ $row['open'] ? ', sin fecha de fin' : '' }}"
                        @class([
                            'absolute inset-y-1 rounded-sm',
                            'bg-primary' => $row['status'] === \App\Modules\Core\Enums\AssignmentStatus::Current,
                            'bg-primary/30' => $row['status'] === \App\Modules\Core\Enums\AssignmentStatus::Ended,
                            'border border-dashed border-primary bg-primary/10' => $row['status'] === \App\Modules\Core\Enums\AssignmentStatus::Scheduled,
                        ])
                        style="left: {{ $row['left'] }}%; width: {{ $row['width'] }}%"
                    ></span>
                </div>
            @endforeach
        </div>
    </div>
@else
    <p class="text-neutral" data-testid="vigencias-empty">Todavía no tiene nombramientos.</p>
@endif
