{{--
    La leyenda de «Demanda por materia», al pie de la matriz: qué es una celda que cuadra, una que se aparta del umbral
    (en `warning`, con su icono de tendencia), una de una carrera sin propuesta (punteada, con el reloj) y el icono de
    laboratorio.
--}}
<div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral" aria-label="Leyenda" data-testid="demand-legend">
    <span class="inline-flex items-center gap-2"><span class="inline-flex items-center rounded border border-base-300 bg-base-100 px-2 py-0.5 font-mono text-[13px] font-semibold text-base-content" aria-hidden="true">5<span class="font-normal text-neutral">/5</span></span>Pedidos/sugeridos: cuadra</span>
    <span class="inline-flex items-center gap-2"><span class="inline-flex items-center gap-1 rounded border border-warning/40 bg-warning/12 px-2 py-0.5 font-mono text-[13px] font-semibold text-warning" aria-hidden="true"><x-icon name="o-arrow-trending-up" class="h-3.5 w-3.5" />6<span class="font-normal opacity-80">/4</span></span>Se aparta {{ $board->settings->highlightThreshold }} % o más</span>
    <span class="inline-flex items-center gap-2"><span class="inline-flex items-center gap-1 rounded border border-dashed border-base-content/35 px-2 py-0.5 font-mono text-[13px] text-neutral" aria-hidden="true"><x-icon name="o-clock" class="h-3.5 w-3.5" />4</span>Sin propuesta enviada: cuenta lo sugerido</span>
    <span class="inline-flex items-center gap-2"><x-icon name="o-beaker" class="h-4 w-4" aria-hidden="true" />Requiere laboratorio</span>
</div>
