{{--
    Los totales de la propuesta (academic-load::pages.proposal): materias con grupos y grupos (los alumnos esperados
    no se suman entre materias: un alumno cuenta en cada una que puede llevar). En la
    retícula van en el renglón de la vista, compactos y al ras de la retícula, para no gastar un renglón entero
    (a 1366 x 768 cada renglón cuenta); en la tabla, en una barra pegada al pie de la ventana, para verlos mientras
    se captura. `$floating` (opcional) la pega al pie.
--}}
@if ($floating ?? false)
    <div class="sticky bottom-3 z-10 grid grid-cols-2 rounded-box border border-base-300 bg-base-100 shadow-float" data-testid="proposal-totals">
        @foreach ([['Materias con grupos', $overview->totals['subjects']], ['Grupos', $overview->totals['groups']]] as [$label, $value])
            <div class="flex flex-col gap-0.5 border-base-300 px-3 py-2 sm:px-5 md:gap-1 md:py-3 [&:nth-child(n+2)]:border-l">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</span>
                <span class="font-mono text-xl font-semibold tabular-nums md:text-2xl">{{ $value }}</span>
            </div>
        @endforeach
    </div>
@else
    <dl class="flex flex-wrap items-baseline gap-x-6 gap-y-1" data-testid="proposal-totals">
        @foreach ([['Materias con grupos', $overview->totals['subjects']], ['Grupos', $overview->totals['groups']]] as [$label, $value])
            <div class="flex items-baseline gap-2">
                <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</dt>
                <dd class="font-mono text-lg font-semibold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
@endif
