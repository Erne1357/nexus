{{--
    Un semestre de core::semester-grid.frame, con su encabezado de dos renglones: «SEMESTRE N» y, debajo, sus
    cifras en Mono (`$figures`: «4 de 6» en la ficha, «27 cr» en el editor) con `$actions`, si llega, a la derecha
    (un botón `btn-xs` con `-my-1` cabe en el renglón). Todos los encabezados miden lo mismo: no llevan notas.
    `$below`, si llega, va debajo de las cifras, a todo el ancho de la columna (en «Por validar», «Colocar aquí» en
    todos los semestres a la vez, así los encabezados siguen midiendo lo mismo). `$sortable`, si llega, es el método de
    Livewire que recibe `(id, posición)` cuando se suelta un mosaico con `wire:sort:item` por su agarre
    (`wire:sort:handle`; D21): solo el editor del plan lo pasa, y solo a quien puede editar la retícula. Los mosaicos
    tienen su renglón fijo en la cuadrícula, así que mientras se arrastra se reasignan los renglones en el orden del
    DOM para que el mosaico se vea moverse; al soltar, el servidor repinta con el orden guardado. `$note` se dice solo a lectores de pantalla («su semestre»), porque
    el fondo de la columna no lo ve quien no ve. Desde 768 px la sección no tiene caja (`md:contents`): su
    encabezado y sus mosaicos se acomodan en la cuadrícula del marco, en la columna `--col`. En celular es una caja
    con sus materias en dos columnas, resaltada si `$current`.
--}}
@props(['semester', 'column', 'current' => false, 'note' => null, 'sortable' => null])

<section {{ $attributes->class([
    'max-md:grid max-md:grid-cols-2 max-md:gap-2 max-md:rounded-box max-md:border max-md:border-base-300 max-md:px-2 max-md:pt-2.5 max-md:pb-3 md:contents',
    'max-md:bg-base-200' => $current,
    'max-md:bg-base-100' => ! $current,
]) }} aria-label="Semestre {{ $semester }}" data-semester="{{ $semester }}" style="--col: {{ $column }}"
    @if ($sortable)
        wire:sort="{{ $sortable }}"
        wire:sort:config="{ forceFallback: /firefox/i.test(navigator.userAgent), fallbackOnBody: true, animation: 0, onChange: (event) => { const tiles = Array.from(event.from.querySelectorAll(':scope > [data-subject]')); const rows = tiles.map((tile) => Number.parseInt(tile.style.getPropertyValue('--row'), 10)).sort((a, b) => a - b); tiles.forEach((tile, index) => tile.style.setProperty('--row', rows[index])); } }"
    @endif>
    <div data-semester-header class="max-md:col-span-2 max-md:px-0.5 md:col-(--col) md:row-start-1 md:px-2.5 md:pt-2.5">
        <h3 class="whitespace-nowrap text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Semestre {{ $semester }}@if ($note)<span class="sr-only">, {{ $note }}</span>@endif</h3>
        <div class="flex h-4 items-center justify-between gap-1 font-mono text-xs text-neutral">
            <span>{{ $figures ?? '' }}</span>
            {{ $actions ?? '' }}
        </div>
        {{ $below ?? '' }}
    </div>
    {{ $slot }}
</section>
