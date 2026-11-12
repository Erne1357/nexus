{{--
    La modalidad de una pantalla (M4, WithModalityScope). Con pocas opciones es el filtro segmentado de DESIGN.md
    («Filtro segmentado»: un `join` de botones de 40 px, 44 en celular, con `aria-pressed` y la elegida en primary; si no
    cabe, se desplaza de lado dentro de su caja, nunca la página); con más de cuatro, un select que cambia al elegir. No
    se pinta si hay una sola opción (MS16, supuesto por confirmar): con solo Presencial activa no aparece.

    `options`: las modalidades de modalityOptions(); `selected`: la clave de la que la pantalla muestra
    (requiredModality()); `property`: la propiedad que fija, `modalidad` por omisión.

    <x-core::modality-scope :options="$modalityOptions" :selected="$shownModality->code" />
--}}
@props(['options', 'selected' => null, 'property' => 'modalidad'])

@php($choices = collect($options)->map(fn ($modality): array => ['id' => $modality->code, 'name' => $modality->name])->values())

@if ($choices->count() > 1)
    @if ($choices->count() <= 4)
        <div {{ $attributes->class(['relative min-w-0 max-w-full overflow-x-auto overscroll-x-contain']) }}>
            <div role="group" aria-label="Modalidad" class="join" data-testid="modality-scope">
                @foreach ($choices as $choice)
                    <button type="button"
                        wire:click="$set('{{ $property }}', {{ \Illuminate\Support\Js::from($choice['id']) }})"
                        aria-pressed="{{ $selected === $choice['id'] ? 'true' : 'false' }}"
                        data-testid="modality-scope-{{ $choice['id'] }}"
                        @class(['btn join-item flex-none flex-nowrap whitespace-nowrap px-3 max-md:h-11', 'btn-primary' => $selected === $choice['id']])>{{ $choice['name'] }}</button>
                @endforeach
            </div>
        </div>
    @else
        {{-- El select de MaryUI (`x-select`) con su marcado, pero con la opción elegida marcada desde el servidor: la
             propiedad puede traer una clave que se ignora o venir vacía en una pantalla que muestra la primera. --}}
        <label {{ $attributes->class(['select w-full']) }}>
            <select aria-label="Modalidad" wire:change="$set('{{ $property }}', $event.target.value)" data-testid="modality-scope">
                @foreach ($choices as $choice)
                    <option value="{{ $choice['id'] }}" @selected($selected === $choice['id'])>{{ $choice['name'] }}</option>
                @endforeach
            </select>
        </label>
    @endif
@endif
