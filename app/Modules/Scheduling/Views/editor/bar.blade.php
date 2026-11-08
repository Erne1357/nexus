{{--
    La barra sobre la semana. En la vista por semestre, con un grupo elegido: su color, «CLAVE · grupo», su materia, sus
    horas, la leyenda de las celdas (cabe, choca, no se puede) y, si no se puede acomodar, el motivo, una sola vez;
    a la derecha «Grupo» (su cajón) y «Quitar selección» (o Esc). Por docente: el docente, «Semana en todos los campus»
    y la leyenda de su disponibilidad. Por salón: el salón y la leyenda de sus bloqueos. Sin docente o salón que
    mostrar, lo dice.
--}}
@php
    $swatch = 'inline-grid h-5 w-5 shrink-0 place-items-center rounded-selector border';
@endphp
@if ($view === 'semestre' && $selected)
    @php($done = $selected['placed_hours'] >= $selected['required_hours'])
    <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2 rounded-box border border-primary bg-base-100 px-3 py-2" data-testid="selection-bar">
        <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 font-medium">
            <span @class([$chainClasses[$selectedChain % count($chainClasses)], 'h-6 w-3 shrink-0 rounded-[2px] bg-(--chain)']) aria-hidden="true"></span>
            <b class="font-mono font-semibold whitespace-nowrap">{{ $selected['subject']['code'] }} · {{ $selected['code'] }}</b>
            <span>{{ $selected['subject']['name'] }}</span>
            <span @class(['font-mono whitespace-nowrap', 'text-success' => $done, 'text-warning' => ! $done])>{{ $selected['placed_hours'] }} de {{ $selected['required_hours'] }} h</span>
        </span>
        <span class="flex flex-wrap items-center gap-x-3.5 gap-y-1 text-[13px] text-neutral">
            <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} border-dashed border-success bg-success/15 text-success" aria-hidden="true"><x-icon name="o-plus" class="h-3.5 w-3.5" /></span>Cabe</span>
            <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} border-error text-error {{ $fills['clash'] }}" aria-hidden="true"><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5" /></span>Choca: se guarda marcada</span>
            <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} border-base-300 text-secondary-muted {{ $fills['blocked'] }}" aria-hidden="true"><x-icon name="o-no-symbol" class="h-3.5 w-3.5" /></span>No se puede</span>
        </span>
        @if ($editReason)
            <span class="flex basis-full items-center gap-1.5 text-sm text-neutral" data-testid="edit-reason"><x-icon name="o-lock-closed" class="h-4 w-4 shrink-0" aria-hidden="true" />{{ $editReason }}</span>
        @endif
        <span class="ms-auto flex gap-1.5">
            <x-button label="Grupo" icon="o-information-circle" class="btn-ghost btn-sm" wire:click="openGroup('{{ $selected['id'] }}')" data-opener />
            <x-button label="Quitar selección" icon="o-x-mark" class="btn-outline btn-sm" wire:click="clearSelection" data-testid="clear-selection" />
        </span>
    </div>
@elseif ($view === 'docente')
    <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2 rounded-box border border-base-300 bg-base-100 px-3 py-2" data-testid="person-bar">
        @if ($person)
            <span class="flex items-center gap-2"><x-icon name="o-user" class="h-5 w-5 shrink-0" aria-hidden="true" /><b class="font-medium">{{ $person['name'] }}</b><span class="text-neutral">Semana en todos los campus</span></span>
            <span class="ms-auto flex flex-wrap items-center gap-x-3.5 gap-y-1 text-[13px] text-neutral">
                <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} border-base-300 text-secondary-muted {{ $fills['blocked'] }}" aria-hidden="true"><x-icon name="o-no-symbol" class="h-3.5 w-3.5" /></span>No disponible</span>
                <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} border-base-300 bg-success/15 text-success" aria-hidden="true"><x-icon name="o-star" class="h-3.5 w-3.5" /></span>Preferido</span>
                <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} border-dashed border-base-300 bg-base-200" aria-hidden="true"></span>Otro semestre u otro campus</span>
            </span>
        @else
            <span class="flex items-center gap-2 text-neutral"><x-icon name="o-user-minus" class="h-5 w-5" aria-hidden="true" />Los grupos de este semestre no tienen docente.</span>
        @endif
    </div>
@elseif ($view === 'salon')
    <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2 rounded-box border border-base-300 bg-base-100 px-3 py-2" data-testid="person-bar">
        @if ($person)
            <span class="flex items-center gap-2"><x-icon name="o-building-office" class="h-5 w-5 shrink-0" aria-hidden="true" /><b class="font-mono font-semibold">{{ $person['name'] }}</b><span class="text-neutral">Semana del salón</span></span>
            <span class="ms-auto flex flex-wrap items-center gap-x-3.5 gap-y-1 text-[13px] text-neutral">
                <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} border-base-300 text-secondary-muted {{ $fills['blocked'] }}" aria-hidden="true"><x-icon name="o-lock-closed" class="h-3.5 w-3.5" /></span>Bloqueado</span>
                <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} border-dashed border-base-300 bg-base-200" aria-hidden="true"></span>Otro semestre</span>
            </span>
        @else
            <span class="flex items-center gap-2 text-neutral"><x-icon name="o-building-office" class="h-5 w-5" aria-hidden="true" />Las sesiones de este semestre no tienen salón.</span>
        @endif
    </div>
@endif
