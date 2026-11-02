{{--
    La línea de tiempo del calendario del periodo (DESIGN.md, "Línea de tiempo de etapas"), la maqueta B de la ficha de
    core::pages.term: el encabezado de meses y semanas y un renglón por etapa, con su número, su nombre, su estado, su
    barra general y una barra delgada por cada campus con ventana propia; la línea «Hoy» cruza todo. Cada renglón es
    un botón que abre el panel de la etapa (`editStage`). Se acomoda por el ancho de su caja (container queries):
    desde 672 px es la línea de tiempo con la columna de nombres a la izquierda; debajo, cada renglón apila su nombre,
    sus fechas y una mini barra sobre las mismas semanas. `$timeline` es un StageTimeline; `$canManageCalendar`, si el
    usuario puede editar las etapas.
--}}
@php($weeks = max(count($timeline->weeks), 1))

<div class="@container relative overflow-hidden" data-testid="stage-timeline">
    <x-core::timeline.head
        :timeline="$timeline"
        cols="grid-cols-1 @2xl:grid-cols-[232px_minmax(0,1fr)] @4xl:grid-cols-[292px_minmax(0,1fr)]"
        label-class="hidden items-end pb-2 ps-4 @2xl:flex"
        flag-class="hidden @2xl:block"
    />

    @foreach ($timeline->rows as $index => $row)
        @php($window = $row['window'])
        @php($daysLeft = \App\Modules\Core\Support\StageTimeline::daysLeftLabel($window))
        <button
            type="button"
            wire:key="stage-row-{{ $window->stage->value }}"
            wire:click="editStage('{{ $window->stage->value }}')"
            @disabled(! $canManageCalendar)
            @if (! $canManageCalendar) title="Solo el administrador o la subdirección editan el calendario." @endif
            aria-label="Editar {{ $window->stage->label() }}"
            data-testid="stage-{{ $window->stage->value }}"
            class="grid min-h-[62px] w-full cursor-pointer border-t border-base-300 text-start first-of-type:border-t-0 hover:bg-base-200 focus-visible:z-[3] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed @2xl:grid-cols-[232px_minmax(0,1fr)] @4xl:grid-cols-[292px_minmax(0,1fr)]"
        >
            <span class="grid min-w-0 grid-cols-[22px_minmax(0,1fr)_auto] items-center gap-x-2 gap-y-0.5 py-3 pe-3 ps-4 pb-1 @2xl:grid-cols-[18px_minmax(0,1fr)] @2xl:py-2.5 @4xl:grid-cols-[22px_minmax(0,1fr)_auto]">
                <span class="font-mono font-semibold text-neutral">{{ $index + 1 }}</span>

                <span class="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1.5 @2xl:grid @2xl:justify-items-start @2xl:gap-1.5">
                    <b class="basis-full text-[14.5px] font-bold leading-tight">{{ $window->stage->label() }}</b>
                    <x-core::status-badge :tone="$window->status->tone()" :label="$window->status->label()" />

                    @if ($window->startsOn !== null)
                        <span class="min-w-0 flex-1 text-sm text-neutral @2xl:hidden">{{ \App\Modules\Core\Support\StageTimeline::rangeLabel($window) }} · {{ (int) $window->startsOn->diffInDays($window->endsOn) + 1 }} días{{ $daysLeft === null ? '' : ' · '.$daysLeft }}</span>

                        @if ($daysLeft !== null)
                            <span class="hidden text-sm text-neutral @2xl:block">{{ $daysLeft }}</span>
                        @endif
                    @endif

                    @foreach ($row['campuses'] as $bar)
                        <span class="inline-flex basis-full items-center gap-1.5 text-sm text-neutral @2xl:hidden">
                            <i class="inline-block h-1.5 w-3 rounded-[3px] bg-primary"></i>
                            <span><b class="font-mono font-semibold text-base-content">{{ $bar['code'] }}</b> · {{ \App\Modules\Core\Support\StageTimeline::rangeLabel($bar['window']) }}</span>
                        </span>
                    @endforeach
                </span>

                <span class="col-start-3 inline-flex items-center gap-1.5 justify-self-end text-sm font-medium text-primary @2xl:col-start-2 @2xl:justify-self-start @4xl:col-start-3 @4xl:justify-self-end">
                    <x-icon name="o-pencil-square" class="h-4 w-4" aria-hidden="true" />Editar
                </span>
            </span>

            <span
                class="relative flex min-w-0 flex-col justify-center gap-1.5 bg-[repeating-linear-gradient(to_right,var(--color-base-300)_0_1px,transparent_1px_calc(100%/var(--wk)))] py-2 pb-3 @2xl:py-2.5"
                style="--wk: {{ $weeks }}"
            >
                @if ($row['left'] === null)
                    <span class="absolute inset-x-2 inset-y-0.5 flex items-center rounded border border-dashed border-base-300 ps-3 text-sm text-neutral @max-2xl:ps-6">Sin fecha</span>
                @else
                    @php($end = $row['left'] + $row['width'])
                    <span class="relative block h-5">
                        <i @class([
                            'absolute inset-y-0 block min-w-1.5 rounded',
                            'bg-info' => $window->status === \App\Modules\Core\Enums\StageStatus::InProgress,
                            'bg-success' => $window->status === \App\Modules\Core\Enums\StageStatus::Finished,
                            'border border-base-content/50 bg-base-content/15' => $window->status === \App\Modules\Core\Enums\StageStatus::Upcoming,
                        ]) style="left: {{ $row['left'] }}%; width: {{ $row['width'] }}%"></i>
                        <span @class([
                            'absolute top-1/2 hidden -translate-y-1/2 whitespace-nowrap font-mono text-[12.5px] font-semibold tabular-nums @2xl:block',
                            'ps-2' => $end <= 72,
                            'pe-2 text-end' => $end > 72,
                        ]) style="{{ $end <= 72 ? 'left: '.$end.'%' : 'right: '.(100 - $row['left']).'%' }}">{{ \App\Modules\Core\Support\StageTimeline::rangeLabel($window) }}</span>
                    </span>
                @endif

                @foreach ($row['campuses'] as $bar)
                    @php($barEnd = $bar['left'] + $bar['width'])
                    <span class="relative block h-2">
                        <i class="absolute inset-y-0 block min-w-1.5 rounded-[3px] bg-primary" style="left: {{ $bar['left'] }}%; width: {{ $bar['width'] }}%"></i>
                        <span @class([
                            'absolute top-1/2 hidden -translate-y-1/2 whitespace-nowrap text-xs text-neutral @2xl:block',
                            'ps-2' => $barEnd <= 72,
                            'pe-2 text-end' => $barEnd > 72,
                        ]) style="{{ $barEnd <= 72 ? 'left: '.$barEnd.'%' : 'right: '.(100 - $bar['left']).'%' }}"><b class="font-mono font-semibold text-base-content">{{ $bar['code'] }}</b> · {{ \App\Modules\Core\Support\StageTimeline::rangeLabel($bar['window']) }}</span>
                    </span>
                @endforeach

                @if ($timeline->today !== null)
                    <i class="absolute inset-y-0 -ms-px w-0.5 bg-base-content/75 @2xl:hidden" style="left: {{ $timeline->today }}%"></i>
                @endif
            </span>
        </button>
    @endforeach

    @if ($timeline->today !== null)
        <div class="pointer-events-none absolute inset-x-0 bottom-0 top-[52px] z-[1] hidden grid-cols-[232px_minmax(0,1fr)] @2xl:grid @4xl:grid-cols-[292px_minmax(0,1fr)]">
            <div></div>
            <div class="relative"><i class="absolute inset-y-0 -ms-px w-0.5 bg-base-content/75" style="left: {{ $timeline->today }}%"></i></div>
        </div>
    @endif

    <div class="relative z-[2] flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-base-300 bg-base-200/60 px-4 py-2.5 text-sm text-neutral">
        <span class="inline-flex items-center gap-2"><i class="inline-block h-3 w-5.5 rounded-[3px] bg-info"></i>En curso</span>
        <span class="inline-flex items-center gap-2"><i class="inline-block h-3 w-5.5 rounded-[3px] border border-base-content/50 bg-base-content/15"></i>Próxima</span>
        <span class="inline-flex items-center gap-2"><i class="inline-block h-3 w-5.5 rounded-[3px] bg-success"></i>Terminada</span>
        <span class="inline-flex items-center gap-2"><i class="inline-block h-1.5 w-5.5 rounded-[3px] bg-primary"></i>Ventana propia de un campus</span>
        @if ($timeline->today !== null)
            <span class="inline-flex items-center gap-2"><i class="inline-block h-3.5 w-0.5 bg-base-content"></i>Hoy, {{ \App\Modules\Core\Support\StageTimeline::dateLabel(today()->toImmutable()) }}</span>
        @endif
    </div>
</div>
