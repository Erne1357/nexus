{{--
    Los totales de la propuesta (academic-load::pages.proposal): materias con grupos, grupos y alumnos esperados. En la
    retícula van arriba; en la tabla, en una barra pegada al pie de la ventana, para verlos mientras se captura.
    `$floating` (opcional) la pega al pie.
--}}
<div @class([
    'grid grid-cols-3 rounded-box border border-base-300 bg-base-100',
    'overflow-hidden' => ! ($floating ?? false),
    'sticky bottom-3 z-10 shadow-float' => $floating ?? false,
]) data-testid="proposal-totals">
    @foreach ([['Materias con grupos', $overview->totals['subjects']], ['Grupos', $overview->totals['groups']], ['Alumnos esperados', $overview->totals['expected']]] as [$label, $value])
        <div class="flex flex-col gap-0.5 border-base-300 px-3 py-2 sm:px-5 md:gap-1 md:py-3 [&:nth-child(n+2)]:border-l">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</span>
            <span class="font-mono text-xl font-semibold tabular-nums md:text-2xl">{{ $value }}</span>
        </div>
    @endforeach
</div>
