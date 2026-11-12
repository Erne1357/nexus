{{--
    El campus de una pantalla (#415, WithCampusFilter): la credencial ya no lo elige. Con pocas opciones es el filtro
    segmentado de DESIGN.md («Filtro segmentado»: un `join` de botones de 40 px, 44 en celular, con `aria-pressed` y el
    elegido en primary; si no cabe, se desplaza de lado dentro de su caja, nunca la página); con más de cuatro
    botones (contando «Todos»), un select que cambia al elegir. No se pinta si hay un solo campus: no hay nada que elegir.

    `options`: los campus de campusOptions(); `selected`: el código del campus que la pantalla muestra (campusFilter()
    o requiredCampus()), null para «todos»; `all`: si la pantalla puede mostrar todos los campus juntos (ofrece
    «Todos»); `property`: la propiedad que fija, `campus` por omisión.

    Con WithCampusFilter, `$campus` es la propiedad pública (el código que pide la URL), así que la pantalla pasa el
    campus que muestra con otro nombre:

    <x-core::campus-filter :options="$campusOptions" :selected="$shownCampus?->code" all />
--}}
@props(['options', 'selected' => null, 'all' => false, 'property' => 'campus', 'compact' => false, 'allLabel' => 'Todos los campus'])

@php
    $choices = collect($options)->map(fn ($campus): array => ['id' => $campus->code, 'name' => $campus->name])->values();
    $segments = $all ? [['id' => null, 'name' => 'Todos'], ...$choices->all()] : $choices->all();
@endphp

@if ($choices->count() > 1)
    @if (! $compact && count($segments) <= 4)
        <div {{ $attributes->class(['relative min-w-0 max-w-full overflow-x-auto overscroll-x-contain']) }}>
            <div role="group" aria-label="Campus" class="join" data-testid="campus-filter">
                @foreach ($segments as $segment)
                    <button type="button"
                        wire:click="$set('{{ $property }}', {{ \Illuminate\Support\Js::from($segment['id']) }})"
                        aria-pressed="{{ $selected === $segment['id'] ? 'true' : 'false' }}"
                        data-testid="campus-filter-{{ $segment['id'] ?? 'todos' }}"
                        @class(['btn join-item flex-none flex-nowrap whitespace-nowrap px-3 max-md:h-11', 'btn-primary' => $selected === $segment['id']])>{{ $segment['name'] }}</button>
                @endforeach
            </div>
        </div>
    @else
        {{-- El select de MaryUI (`x-select`) con su marcado, pero con la opción elegida marcada desde el servidor: la
             propiedad puede traer un código que se ignora o venir vacía en una pantalla que muestra el primero. --}}
        <label {{ $attributes->class(['select w-full']) }}>
            <select aria-label="Campus" wire:change="$set('{{ $property }}', $event.target.value)" data-testid="campus-filter">
                @if ($all)
                    <option value="" @selected($selected === null)>{{ $allLabel }}</option>
                @endif
                @foreach ($choices as $choice)
                    <option value="{{ $choice['id'] }}" @selected($selected === $choice['id'])>{{ $choice['name'] }}</option>
                @endforeach
            </select>
        </label>
    @endif
@endif
