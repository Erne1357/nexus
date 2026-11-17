{{--
    El bloque de arriba de una evaluación lista: el error ponderado de lo que filtra la página (todo el Tec, un campus, un
    departamento de la materia o los dos) contra la meta, con su veredicto (DM-22); el error con la regla de R1 y cuánto
    lo baja la fórmula, las materias dentro del 15 % (DM-23), el sesgo en alumnos por materia y los grupos sugeridos
    contra los abiertos; debajo, su regla grande. Luego, el aviso de los alumnos que no se evalúan (DM-27).
    `$current` (ForecastEvaluationItem) y `$report` (ForecastReport).
--}}
@php
    $summary = $report->summary;
    $counts = $report->counts;
    $scope = match (true) {
        $report->campus !== null && $report->department !== null => "{$report->campus->name} · {$report->department->code}",
        $report->campus !== null => $report->campus->name,
        $report->department !== null => "{$report->department->code} · {$report->department->name}",
        default => 'Todo el Tec',
    };
    $improvement = $summary->improvement();
    $bias = $summary->bias;
@endphp
<section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-title" data-testid="summary-block">
    <div class="flex flex-wrap items-start gap-x-10 gap-y-4 px-5 pt-[18px] pb-1.5 max-md:px-3.5 max-md:pt-3.5">
        <div class="grid gap-1.5">
            <h2 id="summary-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $scope }} · <span class="font-mono tabular-nums">{{ number_format($counts['counted']) }}</span> {{ $counts['counted'] === 1 ? 'materia' : 'materias' }}</h2>
            <div class="flex flex-wrap items-center gap-3.5">
                <x-academic-load::percent :value="$summary->weightedError" class="text-[44px] font-semibold leading-none tracking-[-0.02em] max-md:text-4xl" data-testid="global-error" />
                <x-core::status-badge :tone="$summary->confidence->tone()" :label="$summary->confidence->label()" :icon="$summary->confidence->icon()" class="h-7.5 px-3 text-sm [&_svg]:h-4 [&_svg]:w-4" />
            </div>
        </div>
        <dl class="grid flex-[1_1_30rem] grid-cols-2 gap-x-7 gap-y-2.5 pt-1 max-md:basis-full max-md:grid-cols-1">
            <div>
                <dt class="text-[13px] text-neutral">Con la regla de R1</dt>
                <dd class="mt-0.5 text-[15px]">
                    <x-academic-load::percent :value="$summary->baselineError" class="font-semibold" />
                    @if ($improvement !== null)
                        <span class="text-neutral">· la fórmula lo {{ $improvement >= 0 ? 'baja' : 'sube' }} {{ number_format(abs($improvement) * 100, 1) }} puntos</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-[13px] text-neutral">Materias dentro del 15&nbsp;%</dt>
                <dd class="mt-0.5 text-[15px]">
                    <x-academic-load::percent :value="$summary->withinShare" :digits="0" class="font-semibold" />
                    <span class="text-neutral">· <span class="font-mono tabular-nums">{{ number_format($counts['within']) }}</span> de <span class="font-mono tabular-nums">{{ number_format($counts['withStudents']) }}</span></span>
                </dd>
            </div>
            <div>
                <dt class="text-[13px] text-neutral">Sesgo</dt>
                <dd class="mt-0.5 text-[15px]">
                    <span class="font-mono font-semibold tabular-nums">{{ round($bias, 1) == 0 ? '0.0' : ($bias > 0 ? '+' : '−').number_format(abs($bias), 1) }}</span>
                    <span class="text-neutral">alumnos por materia · {{ round($bias, 1) == 0 ? 'sin sesgo' : ($bias > 0 ? 'sobreestima' : 'subestima') }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-[13px] text-neutral">Grupos</dt>
                <dd class="mt-0.5 text-[15px]">
                    <span class="font-mono font-semibold tabular-nums">{{ number_format($counts['suggestedGroups']) }}</span> <span class="text-neutral">sugeridos,</span>
                    <span class="font-mono font-semibold tabular-nums">{{ $counts['openedGroups'] === null ? '—' : number_format($counts['openedGroups']) }}</span> <span class="text-neutral">abiertos</span>
                </dd>
            </div>
        </dl>
    </div>
    <div class="px-5 pt-[34px] pb-4 max-md:px-[18px] max-md:pb-3.5">
        <x-academic-load::forecast-rule :metrics="$summary" big />
        <div class="relative mt-1.5 h-[18px] font-mono text-xs text-neutral" aria-hidden="true">
            @foreach ([0, 15, 30, 45, 60] as $tick)
                <span @class(['absolute', 'translate-x-0' => $tick === 0, '-translate-x-full' => $tick === 60, '-translate-x-1/2' => $tick !== 0 && $tick !== 60]) style="left: {{ round($tick / 60 * 100, 2) }}%">{{ $tick }}<span class="ms-[0.14em] font-sans">%</span></span>
            @endforeach
        </div>
    </div>
</section>

@include('academic-load::forecast-accuracy.out-of-scope', ['current' => $current])
