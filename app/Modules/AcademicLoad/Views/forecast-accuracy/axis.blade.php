{{--
    El eje de la gráfica por carrera: «Carrera», la escala de 0 a 60 % con la meta del 15 % marcada y «Error · R1».
    Debajo de 48rem de caja solo la escala, a todo el ancho, alineada con la regla.
--}}
<div class="grid h-10 grid-cols-1 items-center border-b border-base-300 bg-base-200 px-3.5 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral @3xl:grid-cols-[minmax(10rem,13rem)_minmax(0,1fr)_8rem_16px] @3xl:gap-x-4 @3xl:px-4 @5xl:grid-cols-[minmax(12rem,17rem)_minmax(0,1fr)_9.5rem_18px] @5xl:gap-x-[22px]" aria-hidden="true">
    <span class="hidden @3xl:block">Carrera</span>
    <span class="relative h-full">
        @foreach ([0, 15, 30, 45, 60] as $tick)
            <span @class([
                'absolute top-1/2 -translate-y-1/2 whitespace-nowrap font-mono text-xs normal-case tracking-normal',
                'translate-x-0' => $tick === 0,
                '-translate-x-full' => $tick === 60,
                '-translate-x-1/2' => $tick !== 0 && $tick !== 60,
                'font-semibold text-success' => $tick === 15,
            ]) style="left: {{ round($tick / 60 * 100, 2) }}%">{{ $tick === 15 ? 'Meta 15' : $tick }}<span class="ms-[0.14em] font-sans">%</span></span>
        @endforeach
    </span>
    <span class="hidden text-end @3xl:col-span-2 @3xl:block">Error · R1</span>
</div>
