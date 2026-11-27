{{--
    La modalidad de una pantalla (M4, WithModalityScope): un select más del renglón de selectores, con su etiqueta
    «Modalidad» (maqueta U2, opción A «Selectores en la página», elegida por el usuario el 12 de noviembre de 2026), que
    cambia al elegir. No se pinta si hay una sola opción (MS16, supuesto por confirmar): con solo Presencial activa no
    aparece, y la pantalla dice «Presencial» como dato.

    `options`: las modalidades de modalityOptions(); `selected`: la clave de la que la pantalla muestra
    (requiredModality()); `property`: la propiedad que fija, `modalidad` por omisión.

    <x-core::modality-scope :options="$modalityOptions" :selected="$shownModality->code" />
--}}
@props(['options', 'selected' => null, 'property' => 'modalidad'])

@php($choices = collect($options)->map(fn ($modality): array => ['id' => $modality->code, 'name' => $modality->name])->values())

@if ($choices->count() > 1)
    {{-- El marcado del select de MaryUI (`x-select` con `label`), con la opción elegida marcada desde el servidor: la
         propiedad puede traer una clave que se ignora o venir vacía en una pantalla que muestra la primera. --}}
    <fieldset {{ $attributes->class(['fieldset min-w-0 py-0']) }} data-testid="modality-field">
        <legend class="fieldset-legend mb-0.5">Modalidad</legend>
        <label class="select w-full max-md:h-11">
            <select aria-label="Modalidad" wire:change="$set('{{ $property }}', $event.target.value)" data-testid="modality-scope">
                @foreach ($choices as $choice)
                    <option value="{{ $choice['id'] }}" @selected($selected === $choice['id'])>{{ $choice['name'] }}</option>
                @endforeach
            </select>
        </label>
    </fieldset>
@endif
