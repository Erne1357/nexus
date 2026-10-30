{{--
    Muestra de 24 px de un tipo de disponibilidad, con el relleno y el icono de la celda (AvailabilityKind): en la
    leyenda y en las herramientas del editor. `$kind` es el AvailabilityKind o null (disponible); `$icon` cambia el
    icono (la goma de «Borrar»). El icono es decorativo: el nombre va junto a la muestra.
--}}
@props(['kind' => null, 'icon' => null])

@php($icon ??= $kind?->icon())

<span {{ $attributes->class([
    'inline-grid h-6 w-6 shrink-0 place-items-center rounded-selector border border-base-300',
    \App\Modules\Faculty\Enums\AvailabilityKind::fillClasses(),
    'text-secondary-muted' => $kind === \App\Modules\Faculty\Enums\AvailabilityKind::Unavailable,
    'text-success' => $kind === \App\Modules\Faculty\Enums\AvailabilityKind::Preferred,
]) }} @if ($kind) data-kind="{{ $kind->value }}" @endif aria-hidden="true">
    @if ($icon)
        <x-icon :name="$icon" class="h-4 w-4" />
    @endif
</span>
