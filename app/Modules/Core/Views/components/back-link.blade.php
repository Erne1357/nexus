{{--
    El enlace de regreso de una página (tarea 1.24.3): con `validation` (ValidationReturn::url() vía el trait
    ReturnsToValidation) dice «Regresar» y vuelve a la validación de catálogos, al departamento y catálogo de donde
    se salió; sin él conserva el regreso de siempre (`href` y `label`) y, si la página no tenía uno, no pinta nada.

    <x-core::back-link :validation="$this->validationReturnUrl()" :href="route('faculty.teachers')" label="Docentes" />
--}}
@props(['validation' => null, 'href' => null, 'label' => null])

@if ($validation !== null)
    <a href="{{ $validation }}" wire:navigate {{ $attributes->class(['inline-flex max-w-full items-center gap-1.5 font-medium text-primary']) }} data-testid="back-to-validation">
        <x-icon name="o-chevron-left" class="w-4 h-4 shrink-0" />Regresar
    </a>
@elseif ($href !== null)
    <a href="{{ $href }}" wire:navigate {{ $attributes->class(['inline-flex max-w-full items-center gap-1.5 font-medium text-primary']) }}>
        <x-icon name="o-chevron-left" class="w-4 h-4 shrink-0" /><span class="truncate">{{ $label }}</span>
    </a>
@endif
