{{--
    Una carrera en un bloque del re-análisis: la clave y el semestre; la regla; lo esperado (o el nuevo ingreso en primer
    semestre, RA-09), los cupos de la versión vigente en N grupos y los grupos sugeridos (desde 1440 px); la diferencia
    con su porcentaje de lo esperado (la base de RA-04) y la insignia de su clase, o «Sin clasificar». Debajo, las marcas
    «Revisar» y «Sin historia». Desde 1200 px, todo en un renglón; entre 768 y 1199, las cifras bajo la regla; en celular,
    apilado: clave e insignia, la regla a todo el ancho, cifras y diferencia. `$row`, `$block` y `$board`.
--}}
@php
    $percent = \App\Modules\Scheduling\Queries\ReanalysisBoard::percent($row);
    $gap = $row->gap();
    $shown = \App\Modules\Scheduling\Queries\ReanalysisBoard::shownStudents($row);
    $firstSemester = $row->balance === null;
    $signed = fn (int $value): string => $value > 0 ? '+'.number_format($value) : ($value < 0 ? '−'.number_format(-$value) : '0');
@endphp
<li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-2.5 gap-y-2 border-t border-base-300 px-3 py-2.5 first:border-t-0 md:grid-cols-[5rem_minmax(7.5rem,1fr)_5rem_7.5rem] md:gap-x-[18px] md:gap-y-1 md:px-3.5 desktop:grid-cols-[6rem_minmax(10rem,1fr)_minmax(0,auto)_5.5rem_7.5rem]" data-testid="reanalysis-row" data-career="{{ $row->careerCode }}" data-class="{{ \App\Modules\Scheduling\Queries\ReanalysisBoardQuery::classOf($row) }}">
    <span class="col-start-1 row-start-1 flex min-w-0 items-baseline gap-2 md:grid md:gap-0 md:leading-tight">
        <span class="font-mono text-[13.5px] font-semibold tracking-[0.02em]" title="{{ $row->careerName }}">{{ $row->careerCode }}</span>
        @if ($row->semester !== null)
            <span class="whitespace-nowrap text-[12.5px] text-neutral">Sem. {{ $row->semester }}</span>
        @endif
    </span>

    <x-scheduling::reanalysis.rule :row="$row" :scale="$block->scale" :threshold="$board->threshold" class="col-span-2 row-start-2 md:col-span-1 md:col-start-2 md:row-start-1" />

    <span class="col-start-1 row-start-3 flex flex-wrap gap-x-3.5 gap-y-0.5 whitespace-nowrap text-[13px] text-neutral md:col-start-2 md:row-start-2 desktop:col-start-3 desktop:row-start-1 desktop:flex-nowrap">
        <span><b class="font-mono text-[15px] font-semibold tabular-nums text-base-content">{{ number_format($shown) }}</b> <span class="text-[12.5px]">{{ $firstSemester ? 'nuevo ingreso' : 'esperados' }}</span></span>
        <span><b class="font-mono text-[15px] font-semibold tabular-nums text-base-content">{{ number_format($row->offeredSeats) }}</b> <span class="text-[12.5px]">cupos en {{ $row->offeredGroups }}</span></span>
        <span class="hidden min-[1440px]:inline"><span class="text-[12.5px]">sugeridos</span> <b class="font-mono text-[15px] font-semibold tabular-nums text-base-content">{{ $row->suggestedGroups }}</b></span>
    </span>

    <span class="col-start-2 row-start-3 text-end md:col-start-3 md:row-start-1 desktop:col-start-4">
        @if ($firstSemester)
            <span class="text-neutral" aria-hidden="true">—</span><span class="sr-only">Sin diferencia: primer semestre</span>
        @else
            <span class="inline-flex flex-col items-end leading-[1.15]">
                <b @class(['font-mono text-[15px] font-semibold tabular-nums', 'text-error' => $row->balance === \App\Modules\Demand\Enums\DemandBalance::Shortfall, 'text-warning' => $row->balance === \App\Modules\Demand\Enums\DemandBalance::Surplus])>{{ $signed($gap) }}</b>
                @if ($row->offeredSeats === 0 && $row->expectedStudents > 0)
                    <span class="text-[12.5px] text-neutral">sin oferta</span>
                @elseif ($percent === null)
                    <span class="text-[12.5px] text-neutral">sin demanda</span>
                @elseif (abs($percent) > 999)
                    {{-- Con muy pocos esperados el porcentaje se dispara (1 esperado y 35 cupos son −3 400 %): basta decir que pasa de 999 %. --}}
                    <span class="font-mono text-[12.5px] tabular-nums text-neutral">{{ $percent > 0 ? '+' : '−' }}999&nbsp;%+</span>
                @else
                    <x-academic-load::percent :value="$percent / 100" :digits="0" signed class="text-[12.5px] text-neutral" />
                @endif
            </span>
        @endif
    </span>

    <span class="col-start-2 row-start-1 justify-self-end md:col-start-4 md:justify-self-start desktop:col-start-5">
        @if ($firstSemester)
            <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-[13px] text-neutral"><x-icon name="o-minus-circle" class="h-3.5 w-3.5" aria-hidden="true" />Sin clasificar</span>
        @else
            <x-core::status-badge :tone="$row->balance->tone()" :label="$row->balance->label()" :icon="$row->balance->icon()" />
        @endif
    </span>

    @if ($row->needsReview || $row->insufficientHistory)
        <span class="col-span-2 row-start-4 grid gap-0.5 md:col-span-3 md:col-start-2 md:row-start-3 desktop:col-span-4 desktop:row-start-2">
            @if ($row->needsReview)
                <span class="flex items-start gap-1.5 text-[12.5px] leading-snug text-warning" data-testid="mark-review"><x-icon name="o-exclamation-triangle" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span><b class="font-semibold">Revisar:</b> tiene alumnos que pueden llevarla, pero nadie la ha llevado en la historia (materia nueva o que no se abrió).</span></span>
            @endif
            @if ($row->insufficientHistory)
                <span class="flex items-start gap-1.5 text-[12.5px] leading-snug text-neutral" data-testid="mark-history"><x-icon name="o-information-circle" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span><b class="font-semibold">Sin historia:</b> se proyectó sin la historia propia de la materia.</span></span>
            @endif
        </span>
    @endif
</li>
