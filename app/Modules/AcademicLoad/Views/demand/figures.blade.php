{{--
    Las cuatro cifras de «Demanda por materia» (maqueta C), de todo el Tec o, en la lente de carrera con «Departamento»,
    de ese departamento, que se dice arriba de ellas; dos por renglón debajo de 1024 px: materias
    (con diferencia y sin propuesta), grupos sugeridos y grupos pedidos (por campus y los de materias con laboratorio; de
    los pedidos, cuántos son lo sugerido de carreras sin propuesta) y la diferencia, con su porcentaje y las horas a la
    semana de docentes. La diferencia que llega al umbral va en `warning` con su icono. `$figures` es DemandBoard::figures().
--}}
@php($signed = fn (int $n): string => $n > 0 ? "+{$n}" : ($n < 0 ? '−'.abs($n) : '0'))
@php($campusSplit = fn (array $byCampus): array => collect($board->campuses)->map(fn ($campus) => ['campus' => $campus, 'n' => $byCampus[$campus->code] ?? 0])->all())
@php($figuresOf = $filterDepartment ? "{$filterDepartment->code} · {$filterDepartment->name}" : null)
@if ($figuresOf)
    {{-- En la lente de carrera con «Departamento», las cifras son de las materias de ese departamento (como en la maqueta). --}}
    <p class="-mb-1 flex items-start gap-1.5 text-sm text-neutral" data-testid="figures-scope"><x-icon name="o-funnel" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span>Cifras de <span class="font-medium text-base-content"><span class="font-mono">{{ $filterDepartment->code }}</span> · {{ $filterDepartment->name }}</span>, el departamento del filtro.</span></p>
@endif
<section class="grid grid-cols-2 overflow-hidden rounded-box border border-base-300 bg-base-100 lg:grid-cols-4" aria-label="{{ $figuresOf ? "Cifras de {$figuresOf}" : 'Cifras de todo el Tec' }}" data-testid="demand-figures">
    <div class="min-w-0 border-base-300 px-4 py-3.5" data-testid="figure-subjects">
        <p class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Materias</p>
        <p class="font-mono text-[26px] font-semibold leading-tight tabular-nums">{{ $figures['subjects'] }}</p>
        <p class="text-[12.5px] text-neutral"><span class="font-mono tabular-nums">{{ $figures['withDifference'] }}</span> con diferencia · <span class="font-mono tabular-nums">{{ $figures['withoutProposal'] }}</span> sin propuesta</p>
    </div>

    <div class="min-w-0 border-s border-base-300 px-4 py-3.5" data-testid="figure-suggested">
        <p class="flex items-center gap-1.5 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral"><x-icon name="o-sparkles" class="h-3.5 w-3.5" aria-hidden="true" />Grupos sugeridos</p>
        <p class="font-mono text-[26px] font-semibold leading-tight tabular-nums">{{ $figures['suggested'] }}</p>
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[12.5px] text-neutral">
            @if (count($board->campuses) > 1)
                @foreach ($campusSplit($figures['suggestedByCampus']) as $split)
                    <span class="inline-flex items-center gap-1"><x-core::campus-tag :campus="$split['campus']" size="xs" /><span class="font-mono tabular-nums text-base-content">{{ $split['n'] }}</span></span>
                @endforeach
                <span aria-hidden="true">·</span>
            @endif
            <span><span class="font-mono tabular-nums">{{ $figures['suggestedLab'] }}</span> con laboratorio</span>
        </p>
    </div>

    <div class="min-w-0 border-t border-base-300 px-4 py-3.5 lg:border-s lg:border-t-0" data-testid="figure-requested">
        <p class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Grupos pedidos</p>
        <p class="font-mono text-[26px] font-semibold leading-tight tabular-nums">{{ $figures['counted'] }}</p>
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[12.5px] text-neutral">
            @if (count($board->campuses) > 1)
                @foreach ($campusSplit($figures['countedByCampus']) as $split)
                    <span class="inline-flex items-center gap-1"><x-core::campus-tag :campus="$split['campus']" size="xs" /><span class="font-mono tabular-nums text-base-content">{{ $split['n'] }}</span></span>
                @endforeach
                <span aria-hidden="true">·</span>
            @endif
            <span><span class="font-mono tabular-nums">{{ $figures['countedLab'] }}</span> con laboratorio</span>
        </p>
        @if ($figures['pendingSuggested'] > 0)
            <p class="mt-0.5 flex items-start gap-1 text-[12.5px] text-neutral" data-testid="figure-pending"><x-icon name="o-clock" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span><span class="font-mono tabular-nums">{{ $figures['pendingSuggested'] }}</span> son lo sugerido de carreras sin propuesta</span></p>
        @endif
    </div>

    <div class="min-w-0 border-s border-t border-base-300 px-4 py-3.5 lg:border-t-0" data-testid="figure-difference">
        <p class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Diferencia</p>
        <p @class(['flex flex-wrap items-baseline gap-x-2 leading-tight', 'text-warning' => $figures['large']])>
            @if ($figures['large'])
                <x-icon :name="$figures['difference'] > 0 ? 'o-arrow-trending-up' : 'o-arrow-trending-down'" class="h-5 w-5 self-center" aria-hidden="true" />
            @endif
            <span class="font-mono text-[26px] font-semibold tabular-nums">{{ $signed($figures['difference']) }}</span>
            @if ($figures['percent'] !== null)
                <span class="whitespace-nowrap text-sm"><span class="font-mono tabular-nums">{{ $signed($figures['percent']) }}</span> %</span>
            @endif
            @if ($figures['large'])
                <span class="sr-only">Se aparta {{ $board->settings->highlightThreshold }} % o más de lo sugerido</span>
            @endif
        </p>
        <p class="text-[12.5px] text-neutral">≈ <span class="font-mono tabular-nums">{{ $signed($figures['hours']) }}</span> h a la semana de docentes</p>
    </div>
</section>
