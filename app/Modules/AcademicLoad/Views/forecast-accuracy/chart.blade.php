{{--
    La gráfica por carrera de una evaluación lista (variante B): el eje, las carreras agrupadas por veredicto (sugerencia
    fuerte, referencia, sin medir; DM-22), la de menor error primero, y la leyenda. `$report` (ForecastReport).
--}}
<section class="@container overflow-hidden rounded-box border border-base-300 bg-base-100" aria-label="Error por carrera" data-testid="accuracy-chart">
    @include('academic-load::forecast-accuracy.axis')
    @foreach ($report->groups() as $confidence => $careers)
        @php($verdict = \App\Modules\AcademicLoad\Enums\ForecastConfidence::from($confidence))
        <div data-testid="group-{{ $confidence }}">
            <h3 @class(['flex items-center gap-2.5 px-4 pt-3.5 pb-2 text-sm font-semibold', 'border-t border-base-300' => ! $loop->first])>
                <x-core::status-badge :tone="$verdict->tone()" :label="$verdict->label()" :icon="$verdict->icon()" />
                <span class="font-normal text-neutral">{{ count($careers) === 1 ? '1 carrera' : count($careers).' carreras' }}</span>
            </h3>
            <ol>
                @foreach ($careers as $career)
                    @include('academic-load::forecast-accuracy.career-row', ['career' => $career, 'open' => true])
                @endforeach
            </ol>
        </div>
    @endforeach
    @include('academic-load::forecast-accuracy.legend')
</section>
