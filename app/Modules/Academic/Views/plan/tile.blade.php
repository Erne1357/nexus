{{--
    Una materia en la retícula del plan (academic::pages.plan), en la caja de mosaico de Core
    (core::semester-grid.tile, la misma de la retícula del alumno): la clave en Mono y, si está inactiva en el
    catálogo, `o-minus-circle` («Inactiva» en `title` y para lectores de pantalla) con relleno `bg-base-200`; el
    nombre; lo que pide en un renglón («Pide» para seriación, «Correq.» para correquisito); y al pie, horas de
    teoría, de práctica y créditos, y «Optativa», que además lleva borde punteado. Su cadena de seriación es la
    franja a la izquierda. Tocarla la abre en el panel; elegida lleva el anillo (data-me) y, con algo resaltado, lo
    que no es de su cadena se atenúa. `$gridRow` es su renglón en la cuadrícula (SemesterGrid).
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
    wire:click="select('{{ $row['placement_id'] }}')"
    :data-subject="$row['code']"
    :aria-pressed="$isSelected ? 'true' : 'false'"
    :data-chain="$row['chain']"
    :data-me="$isSelected"
    :data-on-chain="isset($onChain[$row['subject_id']])"
    @class([
        'hover:border-neutral',
        'border-base-300' => $row['is_required'],
        'border-dashed border-neutral' => ! $row['is_required'],
        'bg-base-100' => $row['is_active'],
        'bg-base-200 text-neutral' => ! $row['is_active'],
    ])
>
    @unless ($row['is_active'])
        <x-slot:icon><span class="shrink-0" title="Inactiva"><x-icon name="o-minus-circle" class="w-4 h-4" aria-hidden="true" /><span class="sr-only">Inactiva</span></span></x-slot:icon>
    @endunless
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
