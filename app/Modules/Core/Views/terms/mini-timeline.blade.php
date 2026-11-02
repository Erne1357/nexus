{{--
    El bloque «Calendario» de Inicio (maqueta B): una línea de tiempo corta con la etapa o etapas en curso y las dos
    que siguen, solo de lectura, para el periodo y el campus activos. `$timeline` es un StageTimeline de solo esas
    etapas y `$caption`, «Ene-Jun 2027 · Campus II». Desde 512 px de ancho es la línea de tiempo con los nombres a
    la izquierda; debajo, cada renglón apila su nombre, sus fechas y una mini barra sobre las mismas semanas.
--}}
@php($weeks = max(count($timeline->weeks), 1))

<section aria-labelledby="home-calendar-title" class="@container max-w-3xl overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="home-calendar">
    <div class="border-b border-base-300 px-4 pb-3 pt-3.5">
        <h2 id="home-calendar-title" class="text-lg font-bold leading-tight">Calendario</h2>
        <p class="text-sm text-neutral">{{ $caption }}</p>
    </div>

    <x-core::timeline.head
        :timeline="$timeline"
        cols="grid-cols-1 @lg:grid-cols-[190px_minmax(0,1fr)]"
        label-class="hidden items-end pb-2 ps-4 @lg:flex"
        flag-class="hidden @lg:block"
    />

    @foreach ($timeline->rows as $row)
        @php($window = $row['window'])
        @php($daysLeft = \App\Modules\Core\Support\StageTimeline::daysLeftLabel($window))
        <div class="grid min-h-[54px] border-t border-base-300 first-of-type:border-t-0 @lg:grid-cols-[190px_minmax(0,1fr)]" data-testid="home-stage-{{ $window->stage->value }}">
            <div class="grid min-w-0 content-center gap-1 py-2 pe-3 ps-4 @lg:pb-2">
                <b class="text-sm font-bold leading-tight">{{ $window->stage->label() }}</b>
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <x-core::status-badge :tone="$window->status->tone()" :label="$window->status->label()" />
                    <span class="text-[12.5px] text-neutral">{{ \App\Modules\Core\Support\StageTimeline::rangeLabel($window) }} · {{ (int) $window->startsOn->diffInDays($window->endsOn) + 1 }} días{{ $daysLeft === null ? '' : ' · '.$daysLeft }}</span>
                </div>
            </div>

            <div
                class="relative flex min-w-0 flex-col justify-center bg-[repeating-linear-gradient(to_right,var(--color-base-300)_0_1px,transparent_1px_calc(100%/var(--wk)))] py-1 pb-2.5 @lg:py-2.5"
                style="--wk: {{ $weeks }}"
            >
                <span class="relative block h-5">
                    <i @class([
                        'absolute inset-y-0 block min-w-1.5 rounded',
                        'bg-info' => $window->status === \App\Modules\Core\Enums\StageStatus::InProgress,
                        'bg-success' => $window->status === \App\Modules\Core\Enums\StageStatus::Finished,
                        'border border-base-content/50 bg-base-content/15' => $window->status === \App\Modules\Core\Enums\StageStatus::Upcoming,
                    ]) style="left: {{ $row['left'] }}%; width: {{ $row['width'] }}%"></i>
                </span>

                @if ($timeline->today !== null)
                    <i class="absolute inset-y-0 -ms-px w-0.5 bg-base-content/75" style="left: {{ $timeline->today }}%"></i>
                @endif
            </div>
        </div>
    @endforeach
</section>
