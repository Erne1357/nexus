{{--
    Las cuatro cifras de «Paralelos» (maqueta B; Parallels::figures()), de las materias que se pueden juntar en el campus
    del filtro, como las de «Demanda por materia»: dos por renglón debajo de 1024 px. Materias (cuántas por armar, pendientes
    y armadas), grupos pedidos (por cuántas carreras), los que bastan juntando y los de menos, con las horas a la semana de
    docente que se ahorran (los de menos por las horas de teoría y práctica de cada materia). De menos va en `success`
    cuando se ahorra.
--}}
@php($signed = fn (int $n): string => $n > 0 ? '−'.$n : ($n < 0 ? '+'.abs($n) : '0'))
<section class="grid grid-cols-2 overflow-hidden rounded-box border border-base-300 bg-base-100 lg:grid-cols-4" aria-label="Paralelos de {{ $parallels->department->name }}{{ $shownCampus ? ' en '.$shownCampus->name : '' }}" data-testid="parallels-figures">
    <div class="min-w-0 px-4 py-3.5" data-testid="figure-subjects">
        <p class="{{ $label }}">Materias</p>
        <p class="font-mono text-[26px] font-semibold leading-tight tabular-nums">{{ $figures['subjects'] }}</p>
        <p class="text-[12.5px] text-neutral">
            <span class="font-mono tabular-nums">{{ $filterCounts['por-armar'] }}</span> por armar ·
            @if ($filterCounts['pendientes'] > 0)
                <span class="font-mono tabular-nums">{{ $filterCounts['pendientes'] }}</span> {{ $filterCounts['pendientes'] === 1 ? 'pendiente' : 'pendientes' }} ·
            @endif
            <span class="font-mono tabular-nums">{{ $figures['arranged'] }}</span> {{ $figures['arranged'] === 1 ? 'armada' : 'armadas' }}
        </p>
    </div>

    <div class="min-w-0 border-s border-base-300 px-4 py-3.5" data-testid="figure-requested">
        <p class="{{ $label }}">Grupos pedidos</p>
        <p class="font-mono text-[26px] font-semibold leading-tight tabular-nums">{{ $figures['requested'] }}</p>
        <p class="text-[12.5px] text-neutral">por <span class="font-mono tabular-nums">{{ $careerCount }}</span> {{ $careerCount === 1 ? 'carrera' : 'carreras' }}, en esas materias</p>
    </div>

    <div class="min-w-0 border-t border-base-300 px-4 py-3.5 lg:border-s lg:border-t-0" data-testid="figure-suggested">
        <p class="flex items-center gap-1.5 {{ $label }}"><x-icon name="o-arrows-pointing-in" class="h-3.5 w-3.5" aria-hidden="true" />Bastan juntando</p>
        <p class="font-mono text-[26px] font-semibold leading-tight tabular-nums">{{ $figures['suggested'] }}</p>
        <p class="text-[12.5px] text-neutral">completos por carrera y mixtos con los restos</p>
    </div>

    <div class="min-w-0 border-s border-t border-base-300 px-4 py-3.5 lg:border-t-0" data-testid="figure-saved">
        <p class="{{ $label }}">De menos</p>
        <p @class(['font-mono text-[26px] font-semibold leading-tight tabular-nums', 'text-success' => $figures['saved'] > 0])>{{ $signed($figures['saved']) }}</p>
        <p class="text-[12.5px] text-neutral">grupos · ≈ <span class="font-mono tabular-nums">{{ abs($hoursSaved) }}</span> h de docente {{ $hoursSaved < 0 ? 'más' : 'menos' }} a la semana</p>
    </div>
</section>
