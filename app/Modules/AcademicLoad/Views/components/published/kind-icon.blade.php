{{--
    El icono de una sesión publicada: `o-beaker` si es práctica, `o-computer-desktop` si es virtual y ninguno si es teoría
    (la leyenda dice «Sin icono: teoría»), con su etiqueta como título. Con `always`, la teoría lleva `o-book-open`
    (la lista de horas del celular y los bloques de la semana, como el editor).
--}}
@props(['kind', 'label' => null, 'always' => false])

@php
    $icon = match ($kind) {
        'practica' => 'o-beaker',
        'virtual' => 'o-computer-desktop',
        default => $always ? 'o-book-open' : null,
    };
    $label ??= match ($kind) {
        'practica' => 'Práctica',
        'virtual' => 'Virtual',
        default => 'Teoría',
    };
@endphp
@if ($icon !== null)
    <span title="{{ $label }}" role="img" aria-label="{{ $label }}" {{ $attributes->class(['inline-flex shrink-0']) }}><x-icon :name="$icon" class="h-3 w-3" /></span>
@endif
