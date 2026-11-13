{{--
    La etiqueta de un campus en la propuesta de carrera y la bandeja (maqueta U1, opción A, «ctag»): su clave en Mono
    dentro de una caja con borde, y el nombre del campus para lectores de pantalla y como título. `size` es `xs` (16 px,
    al pie de un mosaico), `sm` (20 px, en tablas) o `lg` (24 px, en encabezados de columna y en el panel). Sin campus (la columna única de una
    modalidad sin salones, MS5) no pinta nada.
--}}
@props(['campus', 'size' => 'sm'])

@if ($campus !== null)
    <span {{ $attributes->class([
        'inline-flex shrink-0 items-center rounded border border-base-content/30 bg-base-100 font-mono font-semibold leading-none tracking-[0.02em] text-base-content',
        'h-4 px-1 text-[11px]' => $size === 'xs',
        'h-5 px-1.5 text-[11.5px]' => $size === 'sm',
        'h-6 px-2 text-[12.5px]' => $size === 'lg',
    ]) }} title="{{ $campus->name }}"><span aria-hidden="true">{{ $campus->code }}</span><span class="sr-only">{{ $campus->name }}</span></span>
@endif
