<div class="flex items-center gap-2">
    @if ($campuses->count() === 1)
        <span class="flex items-center gap-1 text-sm font-medium" title="Campus activo">
            <x-icon name="o-building-office-2" class="h-4 w-4 opacity-60" />
            {{ $campuses->first()->name }}
        </span>
    @elseif ($campuses->isNotEmpty())
        <x-select
            name="campus"
            aria-label="Campus activo"
            :options="$campuses"
            placeholder="Elige un campus"
            wire:model="campusId"
            wire:change="apply"
        />
    @endif

    @if ($terms->isNotEmpty())
        <x-select
            name="term"
            aria-label="Periodo activo"
            :options="$terms"
            :placeholder="$termId === null ? 'Sin periodo' : null"
            wire:model="termId"
            wire:change="apply"
        />
    @endif
</div>
