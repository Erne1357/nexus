{{--
    Una evaluación en cola o corriendo: cuántas carreras van de cuántas, la barra de avance con la etapa
    (EvaluateForecast::STAGE_*) y lo que lleva; debajo, las carreras que ya terminaron con su regla, sin veredicto de todo
    el Tec (sale al final), y un renglón con las que faltan. La página consulta cada 2 s. `$current`
    (ForecastEvaluationItem), `$report` (ForecastReport) y `$items` (para «Espera a que termine otra»).
--}}
@php
    $queued = $current->status === \App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus::Queued;
    $waiting = $queued && $items->contains(fn ($item): bool => $item->id !== $current->id && $item->status === \App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus::Running);
    $total = $current->careersTotal;
    $missing = $total === null ? null : $total - $current->careersDone;
@endphp
<section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="running-title" data-testid="running-block">
    <div class="flex flex-wrap items-start gap-x-10 gap-y-4 px-5 py-[18px] max-md:px-3.5 max-md:py-3.5">
        <div class="grid gap-1.5">
            <h2 id="running-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $queued ? 'En cola' : 'Calculando' }} · {{ $current->term }}</h2>
            <p class="font-mono text-[44px] font-semibold leading-none tracking-[-0.02em] tabular-nums max-md:text-4xl">
                @if ($total === null)
                    —
                @else
                    {{ $current->careersDone }} <span class="font-sans text-lg font-normal tracking-normal text-neutral">de {{ $total }} carreras</span>
                @endif
            </p>
        </div>
        <div class="grid min-w-0 flex-[1_1_26rem] gap-2 pt-1.5">
            <progress @class(['progress h-2 w-full', 'progress-info' => ! $queued]) value="{{ $queued ? 0 : $current->progress }}" max="100" aria-label="Avance de la evaluación" aria-valuetext="{{ $queued ? 'En cola' : $current->progress.' %' }}"></progress>
            @if ($queued)
                @if ($waiting)
                    <p class="text-sm text-neutral">Espera a que termine la evaluación que está corriendo.</p>
                @endif
            @else
                <p class="text-sm">{{ $current->stage ?? 'Calculando' }}@if ($current->elapsed) <span class="text-neutral">· lleva {{ $current->elapsed }}</span>@endif</p>
                <p class="text-[12.5px] text-neutral">Cada carrera aparece al terminar; lo de todo el Tec sale al final.</p>
            @endif
        </div>
    </div>
</section>

@include('academic-load::forecast-accuracy.out-of-scope', ['current' => $current])

@if ($total !== null)
    <section class="@container overflow-hidden rounded-box border border-base-300 bg-base-100" aria-label="Avance por carrera" data-testid="running-chart">
        @include('academic-load::forecast-accuracy.axis')
        <h3 class="flex items-center gap-2.5 px-4 pt-3.5 pb-2 text-sm font-semibold">
            <span>Carreras</span>
            <span class="font-normal text-neutral">{{ $current->careersDone }} de {{ $total }} listas</span>
        </h3>
        <ol>
            @foreach ($report->careers as $career)
                @include('academic-load::forecast-accuracy.career-row', ['career' => $career, 'open' => false])
            @endforeach
            @if ($missing > 0)
                @include('academic-load::forecast-accuracy.career-row', ['career' => null, 'pending' => $missing === 1 ? 'Falta 1 carrera' : "Faltan {$missing} carreras"])
            @endif
        </ol>
    </section>
@endif
