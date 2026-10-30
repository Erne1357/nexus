{{--
    Un semestre de core::semester-grid.frame, con su encabezado: «SEMESTRE N» y, debajo, sus cifras en Mono
    (`$figures`: «4 de 6» en la ficha, «27 cr» en el editor); `$actions`, si llega, va a su derecha. Todos los
    encabezados miden lo mismo: no llevan notas. `$note` se dice solo a lectores de pantalla («su semestre»), porque
    el fondo de la columna no lo ve quien no ve. Desde 768 px la sección no tiene caja (`md:contents`): su
    encabezado y sus mosaicos se acomodan en la cuadrícula del marco, en la columna `--col`. En celular es una caja
    con sus materias en dos columnas, resaltada si `$current`.
--}}
@props(['semester', 'column', 'current' => false, 'note' => null])

<section {{ $attributes->class([
    'max-md:grid max-md:grid-cols-2 max-md:gap-2 max-md:rounded-box max-md:border max-md:border-base-300 max-md:px-2 max-md:pt-2.5 max-md:pb-3 md:contents',
    'max-md:bg-base-200' => $current,
    'max-md:bg-base-100' => ! $current,
]) }} aria-label="Semestre {{ $semester }}" data-semester="{{ $semester }}" style="--col: {{ $column }}">
    <div class="flex items-start justify-between gap-1 max-md:col-span-2 max-md:px-0.5 md:col-(--col) md:row-start-1 md:px-2.5 md:pt-2.5">
        <h3 class="min-w-0 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
            Semestre {{ $semester }}@if ($note)<span class="sr-only">, {{ $note }}</span>@endif
            <span class="block font-mono text-xs normal-case tracking-normal">{{ $figures ?? '' }}</span>
        </h3>
        {{ $actions ?? '' }}
    </div>
    {{ $slot }}
</section>
