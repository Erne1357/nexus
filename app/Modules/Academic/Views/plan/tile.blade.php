{{--
    Una materia en la retícula del plan (academic::pages.plan): la clave en Mono, el nombre, «Inactiva» si lo
    está en el catálogo, lo que pide («Pide» para seriación, «Correq.» para correquisito), «Optativa» con borde
    punteado, y horas de teoría, de práctica y créditos. Su cadena de seriación es una franja a la izquierda
    (una sombra interior con el token chain-N, que llega en --chain). Tocarla la abre en el panel; elegida
    lleva el anillo (data-me) y, con algo resaltado, lo que no es de su cadena se atenúa.
--}}
@php
    $seriation = array_column(array_filter($row['requires'], fn (array $requisite): bool => $requisite['kind'] === 'seriacion'), 'code');
    $corequisites = array_column(array_filter($row['requires'], fn (array $requisite): bool => $requisite['kind'] !== 'seriacion'), 'code');
@endphp
<button
    type="button"
    wire:key="tile-{{ $row['placement_id'] }}"
    wire:click="select('{{ $row['placement_id'] }}')"
    data-subject="{{ $row['code'] }}"
    aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
    @if ($row['chain'] !== null) data-chain="{{ $row['chain'] }}" style="--chain: var(--color-chain-{{ $row['chain'] % 12 + 1 }})" @endif
    @if ($isSelected) data-me @endif
    @if (isset($onChain[$row['subject_id']])) data-on-chain @endif
    @class([
        'flex min-h-24 flex-col gap-1 rounded-field border px-2.5 py-2 text-left transition-opacity hover:border-neutral motion-reduce:transition-none',
        'group-data-[focus]/grid:not-data-[on-chain]:opacity-20 group-data-[focus]/grid:not-data-[on-chain]:grayscale',
        'data-[me]:ring-2 data-[me]:ring-primary data-[me]:ring-offset-2 data-[me]:ring-offset-base-100',
        'border-base-300' => $row['is_required'],
        'border-dashed border-neutral' => ! $row['is_required'],
        'bg-base-100' => $row['is_active'],
        'bg-base-200 text-neutral' => ! $row['is_active'],
        'shadow-[inset_5px_0_0_var(--chain)] pl-3.5' => $row['chain'] !== null,
    ])
>
    <span class="whitespace-nowrap font-mono text-[12.5px] font-semibold">{{ $row['code'] }}</span>
    <span @class(['line-clamp-3 hyphens-auto break-words text-[13px] leading-snug', 'text-base-content' => $row['is_active']]) lang="es">{{ $row['name'] }}</span>
    @unless ($row['is_active'])
        <x-core::status-badge tone="neutral" label="Inactiva" class="h-5.5 gap-1 self-start px-1.5 text-[11.5px]" />
    @endunless
    @if ($seriation !== [])
        <span class="text-[11.5px] leading-snug text-neutral">Pide @foreach ($seriation as $code)<span class="whitespace-nowrap font-mono font-semibold text-base-content">{{ $code }}</span>@if (! $loop->last), @endif @endforeach</span>
    @endif
    @if ($corequisites !== [])
        <span class="text-[11.5px] leading-snug text-neutral">Correq. @foreach ($corequisites as $code)<span class="whitespace-nowrap font-mono font-semibold text-base-content">{{ $code }}</span>@if (! $loop->last), @endif @endforeach</span>
    @endif
    @unless ($row['is_required'])
        <span class="text-[11.5px] text-neutral">Optativa</span>
    @endunless
    <span class="mt-auto font-mono text-xs" title="Horas de teoría, de práctica y créditos">{{ $row['theory_hours'] }}-{{ $row['practice_hours'] }}-{{ $row['credits'] }}</span>
    @if ($row['is_required'])
        <span class="sr-only">Obligatoria</span>
    @endif
</button>
