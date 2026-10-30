{{--
    Insignia de estado de DESIGN.md ("Insignias de estado"), la misma en toda la aplicación:
    <x-core::status-badge tone="success" label="Activo" />. `tone` es un StatusTone o su valor; `icon`
    cambia el icono del tono (en cola: o-clock; con advertencia: o-exclamation-triangle). El color nunca
    va solo: el texto es obligatorio y el icono queda oculto a los lectores de pantalla. El icono va en
    el slot de x-badge y no en su prop `icon`, porque esa prop no deja ponerle aria-hidden.
--}}
@props(['tone', 'label', 'icon' => null])

@php
    $tone = $tone instanceof \App\Modules\Core\Enums\StatusTone ? $tone : \App\Modules\Core\Enums\StatusTone::from($tone);
    $icon ??= $tone->icon();
@endphp

<x-badge {{ $attributes->class([$tone->classes(), 'h-6.5 gap-1.5 font-medium whitespace-nowrap']) }}>
    @if ($icon)
        <x-icon :name="$icon" class="w-3.5 h-3.5" aria-hidden="true" />
    @endif
    {{ $label }}
</x-badge>
