{{--
    La etiqueta de un campus (maquetas U1 y U2, opción A, «ctag»): su clave en Mono dentro de una caja con borde, y el
    nombre del campus para lectores de pantalla y como título. Dice dónde se da un grupo en las pantallas de la carga
    (la carga es por modalidad y el campus es un filtro, M1) y va en los botones del filtro de campus. `size` es `xs`
    (16 px, al pie de un mosaico), `sm` (20 px, en tablas, listas y botones) o `lg` (24 px, en encabezados de columna y
    en paneles). `decorative`: cuando el nombre del campus ya está escrito al lado (un botón del filtro), la etiqueta se
    oculta a los lectores de pantalla para que no lo repitan. Sin campus (un grupo de una modalidad sin salones, MS4) no
    pinta nada.
--}}
@props(['campus', 'size' => 'sm', 'decorative' => false])

@if ($campus !== null)
    <span {{ $attributes->class([
        'inline-flex shrink-0 items-center rounded border border-base-content/30 bg-base-100 font-mono font-semibold leading-none tracking-[0.02em] text-base-content',
        'h-4 px-1 text-[11px]' => $size === 'xs',
        'h-5 px-1.5 text-[11.5px]' => $size === 'sm',
        'h-6 px-2 text-[12.5px]' => $size === 'lg',
    ]) }} @if ($decorative) aria-hidden="true" @else title="{{ $campus->name }}" @endif>@if ($decorative){{ $campus->code }}@else<span aria-hidden="true">{{ $campus->code }}</span><span class="sr-only">{{ $campus->name }}</span>@endif</span>
@endif
