{{-- Campus y periodo activos, en la tarjeta de la credencial (DESIGN.md, "Estructura"): campos de 44 px con su etiqueta arriba. --}}
<div class="grid gap-2.5">
    @if ($campuses->count() === 1)
        <div class="grid gap-1">
            <span class="text-[11.5px] font-medium tracking-[.09em] text-neutral uppercase">Campus</span>
            <span class="flex h-11 items-center gap-2 rounded-field border border-base-300 px-3 text-base" title="Campus activo">
                <x-icon name="o-building-office-2" class="size-4 text-neutral" />
                {{ $campuses->first()->name }}
            </span>
        </div>
    @elseif ($campuses->isNotEmpty())
        <div class="grid gap-1">
            <span aria-hidden="true" class="text-[11.5px] font-medium tracking-[.09em] text-neutral uppercase">Campus</span>
            <x-select
                name="campus"
                aria-label="Campus activo"
                :options="$campuses"
                placeholder="Elige un campus"
                wire:model="campusId"
                wire:change="apply"
                class="h-11 text-base"
            />
        </div>
    @endif

    @if ($terms->isNotEmpty())
        <div class="grid gap-1">
            <span aria-hidden="true" class="text-[11.5px] font-medium tracking-[.09em] text-neutral uppercase">Periodo</span>
            <x-select
                name="term"
                aria-label="Periodo activo"
                :options="$terms"
                :placeholder="$termId === null ? 'Sin periodo' : null"
                wire:model="termId"
                wire:change="apply"
                class="h-11 text-base"
            />
        </div>
    @endif
</div>
