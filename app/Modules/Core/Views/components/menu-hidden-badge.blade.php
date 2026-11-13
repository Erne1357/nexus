{{--
    La insignia de lo oculto para los demás en el menú (DESIGN.md, "Navigation", MN-02), que solo ve el admin:
    <x-core::menu-hidden-badge label="Oculta" />. Es la insignia neutra de estado, más chica (22 px y 11.5 px) para
    caber en un renglón del menú, con el ojo tachado y su texto: «Oculta» en una entrada o un área, «Oculto» en un
    grupo. `why` lo lleva a data-why para el motivo flotante. `compact` la deja solo con el icono con el menú
    cerrado, donde la columna mide 224 px; el texto vuelve al abrirlo y en la hoja del celular. Queda oculta al
    lector de pantalla: quien la lleva dice el motivo con aria-describedby o con texto sr-only.
--}}
@props(['label', 'why' => null, 'compact' => false])

<span
    data-menu-badge
    aria-hidden="true"
    @if ($why !== null) data-why="{{ $why }}" @endif
    {{ $attributes->class([
        'badge badge-sm badge-neutral badge-soft h-[22px] flex-none gap-1 text-[11.5px] font-medium whitespace-nowrap',
        'px-1 in-data-[menu=open]:ps-[5px] in-data-[menu=open]:pe-[7px]' => $compact,
        'ps-[5px] pe-[7px]' => ! $compact,
    ]) }}
>
    <x-icon name="o-eye-slash" class="w-3.5 h-3.5" />
    <span @class(['hidden in-data-[menu=open]:inline' => $compact])>{{ $label }}</span>
</span>
