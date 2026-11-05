{{--
    Una materia en la retícula del plan (academic::pages.plan), en la caja de mosaico de Core
    (core::semester-grid.tile, la misma de la retícula del alumno): la clave en Mono y, si está inactiva en el
    catálogo, `o-minus-circle` («Inactiva» en `title` y para lectores de pantalla) con relleno `bg-base-200`; el
    nombre; lo que pide en un renglón («Pide» para seriación, «Correq.» para correquisito); y al pie, horas de
    teoría, de práctica y créditos, y «Optativa», que además lleva borde punteado. Su cadena de seriación es la
    franja a la izquierda. Tocarla la abre en el panel; elegida lleva el anillo (data-me) y, con algo resaltado, lo
    que no es de su cadena se atenúa. `$gridRow` es su renglón en la cuadrícula (SemesterGrid). Con `$canEditGrid` se
    arrastra por su agarre «⠿» (D21; flota sobre la esquina del mosaico sin quitarle ancho a la clave; se ve al pasar el cursor, con el foco y siempre en pantallas táctiles, donde
    además no deja que el dedo desplace la página) para cambiar su lugar dentro del semestre.
--}}
@php
    $seriation = array_column(array_filter($row['requires'], fn (array $requisite): bool => $requisite['kind'] === 'seriacion'), 'code');
    $corequisites = array_column(array_filter($row['requires'], fn (array $requisite): bool => $requisite['kind'] !== 'seriacion'), 'code');
    $requisites = implode(' · ', array_filter([
        $seriation === [] ? null : 'Pide '.implode(', ', $seriation),
        $corequisites === [] ? null : 'Correq. '.implode(', ', $corequisites),
    ]));
@endphp
<x-core::semester-grid.tile
    :row="$gridRow"
    :chain="$row['chain']"
    :code="$row['code']"
    :name="$row['name']"
    :muted="! $row['is_active']"
    wire:key="tile-{{ $row['placement_id'] }}"
    :wire:sort:item="$canEditGrid ? $row['placement_id'] : null"
    wire:click="select('{{ $row['placement_id'] }}')"
    :data-subject="$row['code']"
    :aria-pressed="$isSelected ? 'true' : 'false'"
    :data-chain="$row['chain']"
    :data-me="$isSelected"
    :data-on-chain="isset($onChain[$row['subject_id']])"
    @class([
        'group/tile relative hover:border-neutral',
        'border-base-300' => $row['is_required'],
        'border-dashed border-neutral' => ! $row['is_required'],
        'bg-base-100' => $row['is_active'],
        'bg-base-200 text-neutral' => ! $row['is_active'],
    ])
>
    @if ($canEditGrid || ! $row['is_active'])
        <x-slot:icon>
            <span class="flex items-center">
            @unless ($row['is_active'])
                <span class="shrink-0" title="Inactiva"><x-icon name="o-minus-circle" class="w-4 h-4" aria-hidden="true" /><span class="sr-only">Inactiva</span></span>
            @endunless
            @if ($canEditGrid)
                <span wire:sort:handle data-sort-handle aria-hidden="true"
                    class="absolute top-0.5 right-0.5 z-10 cursor-grab touch-none select-none rounded-field bg-base-100/90 px-1.5 py-0.5 text-sm leading-4 text-neutral opacity-0 transition-opacity hover:text-base-content group-hover/tile:opacity-100 group-focus-visible/tile:opacity-100 group-focus-within/tile:opacity-100 pointer-coarse:opacity-100 motion-reduce:transition-none before:content-['⠿']"></span>
            @endif
            </span>
        </x-slot:icon>
    @endif
    @if ($row['is_required'])
        <span class="sr-only">Obligatoria</span>
    @endif

    @if ($requisites !== '')
        <x-slot:requisites :title="$requisites">
            @if ($seriation !== [])Pide <span class="font-mono font-semibold text-base-content">{{ implode(', ', $seriation) }}</span>@endif
            @if ($seriation !== [] && $corequisites !== []) · @endif
            @if ($corequisites !== [])Correq. <span class="font-mono font-semibold text-base-content">{{ implode(', ', $corequisites) }}</span>@endif
        </x-slot:requisites>
    @endif

    <x-slot:start><span title="Horas de teoría, de práctica y créditos">{{ $row['theory_hours'] }}-{{ $row['practice_hours'] }}-{{ $row['credits'] }}</span></x-slot:start>
    @unless ($row['is_required'])
        <x-slot:end><span class="font-sans">Optativa</span></x-slot:end>
    @endunless
</x-core::semester-grid.tile>
