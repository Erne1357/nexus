{{--
    El campus de una pantalla (#415, WithCampusFilter): la credencial ya no lo elige. Con pocas opciones es el filtro
    segmentado de DESIGN.md («Filtro segmentado»: un `join` de botones de 40 px, 44 en celular, con `aria-pressed` y el
    elegido en primary; si no cabe, se desplaza de lado dentro de su caja, nunca la página); con más de cuatro
    botones (contando «Todos»), un select que cambia al elegir. No se pinta si hay un solo campus: no hay nada que elegir.

    `options`: los campus de campusOptions(); `selected`: el código del campus que la pantalla muestra (campusFilter()
    o requiredCampus()), null para «todos»; `all`: si la pantalla puede mostrar todos los campus juntos (ofrece
    «Todos»); `property`: la propiedad que fija, `campus` por omisión.

    `counts` (opcional, las pantallas de la carga; maqueta U2, opción A): cuánto hay en cada campus, por código; cada
    botón lleva entonces la etiqueta del campus (x-core::campus-tag) y su cifra en Mono, y «Todos» la de `total` (sin
    ella, la suma). Un campus que no está en `counts` lleva 0. Debajo de 768 px son columnas iguales, el nombre sobre la
    cifra y sin la etiqueta, para que ningún nombre se corte.

    Con WithCampusFilter, `$campus` es la propiedad pública (el código que pide la URL), así que la pantalla pasa el
    campus que muestra con otro nombre:

    <x-core::campus-filter :options="$campusOptions" :selected="$shownCampus?->code" all />
--}}
@props(['options', 'selected' => null, 'all' => false, 'property' => 'campus', 'compact' => false, 'allLabel' => 'Todos los campus', 'counts' => null, 'total' => null])

@php
    $campuses = collect($options)->values();
    $counted = is_array($counts);
    $segments = [
        ...($all ? [['id' => null, 'name' => 'Todos', 'campus' => null, 'count' => $counted ? ($total ?? array_sum($counts)) : null]] : []),
        ...$campuses->map(fn ($campus): array => ['id' => $campus->code, 'name' => $campus->name, 'campus' => $campus, 'count' => $counted ? (int) ($counts[$campus->code] ?? 0) : null])->all(),
    ];
@endphp

@if ($campuses->count() > 1)
    @if (! $compact && count($segments) <= 4)
        {{-- Con cuentas, debajo de 768 px son columnas iguales con el nombre sobre la cifra y sin la etiqueta (como el
             filtro de referencia de DESIGN.md), para que ningún nombre se corte; sin cuentas, se desplaza de lado. --}}
        <div {{ $attributes->class(['relative min-w-0 max-w-full', 'overflow-x-auto overscroll-x-contain' => ! $counted, 'max-md:w-full' => $counted]) }}>
            <div role="group" aria-label="Campus" @class(['join', 'max-md:grid max-md:w-full' => $counted]) @if ($counted) style="grid-template-columns: repeat({{ count($segments) }}, minmax(0, 1fr))" @endif data-testid="campus-filter">
                @foreach ($segments as $segment)
                    <button type="button"
                        wire:click="$set('{{ $property }}', {{ \Illuminate\Support\Js::from($segment['id']) }})"
                        aria-pressed="{{ $selected === $segment['id'] ? 'true' : 'false' }}"
                        data-testid="campus-filter-{{ $segment['id'] ?? 'todos' }}"
                        @class([
                            'btn join-item flex-nowrap whitespace-nowrap px-3',
                            'flex-none max-md:h-11' => ! $counted,
                            'gap-2 md:flex-none max-md:h-auto max-md:min-h-11 max-md:min-w-0 max-md:flex-col max-md:gap-0 max-md:px-2 max-md:py-1 max-md:leading-tight' => $counted,
                            'btn-primary' => $selected === $segment['id'],
                        ])>
                        @if ($counted && $segment['campus'] !== null)
                            <x-core::campus-tag :campus="$segment['campus']" decorative @class(['max-md:hidden', 'border-primary-content/60 bg-transparent text-primary-content' => $selected === $segment['id']]) />
                        @endif
                        <span @class(['max-md:max-w-full max-md:truncate' => $counted])>{{ $segment['name'] }}</span>
                        @if ($counted)
                            <span @class(['font-mono text-xs tabular-nums', 'text-neutral' => $selected !== $segment['id']]) data-testid="campus-count-{{ $segment['id'] ?? 'todos' }}">{{ $segment['count'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    @else
        {{-- El select de MaryUI (`x-select`) con su marcado, pero con la opción elegida marcada desde el servidor: la
             propiedad puede traer un código que se ignora o venir vacía en una pantalla que muestra el primero. --}}
        <label {{ $attributes->class(['select w-full']) }}>
            <select aria-label="Campus" wire:change="$set('{{ $property }}', $event.target.value)" data-testid="campus-filter">
                @foreach ($segments as $segment)
                    @if ($segment['id'] === null)
                        <option value="" @selected($selected === null)>{{ $allLabel }}{{ $counted ? " ({$segment['count']})" : '' }}</option>
                    @else
                        <option value="{{ $segment['id'] }}" @selected($selected === $segment['id'])>{{ $segment['name'] }}{{ $counted ? " ({$segment['count']})" : '' }}</option>
                    @endif
                @endforeach
            </select>
        </label>
    @endif
@endif
