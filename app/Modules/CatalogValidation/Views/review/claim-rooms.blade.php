{{--
    «Reclamar salones sin dueño» (CV16, confirmado el 20 de noviembre de 2026): los salones activos sin departamento
    dueño de todos los campus, con búsqueda, filtros de campus y edificio, casillas y «Reclamar N seleccionados». Lo
    reclamado pasa al departamento y entra a la revisión por revisar; el primero que reclama se lo queda. La selección
    vive en el navegador (Alpine) y llega a claimRooms(). `$review`, `$claimable` (rooms y total) y `$claimFilters`
    son de review.php; `close()` es del x-drawer.
--}}
@php($buildings = collect($claimFilters['buildings'])->when($claimCampus !== '', fn ($buildings) => $buildings->where('campus_id', $claimCampus))->map(fn ($building): array => ['id' => $building['id'], 'name' => $building['name']])->values()->all())
<div class="flex min-h-dvh flex-col" x-data="{ selected: [], claiming: false }" data-testid="claim-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="claim-rooms-title" class="text-xl font-bold leading-tight">Reclamar salones sin dueño</h2>
            <p class="mt-1 text-sm text-neutral">Para {{ $review->department?->name }} · de todos los campus</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-4 px-5 pt-4 pb-8">
        <p class="text-sm text-neutral">Elige los salones que usa tu departamento: pasan a ser suyos y entran a tu revisión de Salones por revisar. El primero que reclama un salón se lo queda. Reclamar no es obligatorio para validar.</p>

        <x-input wire:model.live.debounce.300ms="claimSearch" aria-label="Buscar salones" placeholder="Clave o nombre" icon="o-magnifying-glass" clearable autocomplete="off" data-testid="claim-search" />

        <div class="grid grid-cols-2 gap-2">
            <x-select wire:model.live="claimCampus" aria-label="Campus" :options="$claimFilters['campuses']" placeholder="Campus" data-testid="claim-campus" />
            <x-select wire:model.live="claimBuilding" aria-label="Edificio" :options="$buildings" placeholder="Edificio" data-testid="claim-building" />
        </div>

        @error('claim')
            <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" :title="$message" data-testid="claim-error" />
        @enderror

        @if ($claimable['rooms']->isEmpty())
            <p class="rounded-box border border-base-300 bg-base-200 p-4 text-sm text-neutral" data-testid="claim-empty">{{ $claimable['total'] === 0 && $claimSearch === '' && $claimCampus === '' && $claimBuilding === '' ? 'No hay salones activos sin dueño.' : 'Ningún salón sin dueño coincide con los filtros.' }}</p>
        @else
            <p class="text-sm text-neutral" data-testid="claim-count">{{ $claimable['total'] === 1 ? '1 salón sin dueño' : $claimable['total'].' salones sin dueño' }}@if ($claimable['total'] > $claimable['rooms']->count()) · se muestran {{ $claimable['rooms']->count() }}@endif</p>
            <ul class="divide-y divide-base-300 rounded-box border border-base-300" aria-label="Salones sin dueño" data-testid="claim-list">
                @foreach ($claimable['rooms'] as $room)
                    <li wire:key="claim-{{ $room->id }}">
                        <label class="flex cursor-pointer items-start gap-3 p-3.5">
                            <input type="checkbox" class="checkbox checkbox-sm mt-0.5" value="{{ $room->id }}" x-model="selected" aria-label="Elegir {{ $room->campus?->code }} {{ $room->code }}" data-testid="claim-select-{{ $room->campus?->code }}-{{ $room->code }}">
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-x-2"><span class="font-mono font-semibold">{{ $room->campus?->code }} · {{ $room->code }}</span>@if ($room->name)<span class="truncate">{{ $room->name }}</span>@endif</span>
                                <span class="block text-[12.5px] text-neutral">{{ $room->building?->code }} · {{ $room->type->label() }} · <span class="font-mono tabular-nums">{{ $room->capacity }}</span> lugares</span>
                            </span>
                        </label>
                    </li>
                @endforeach
            </ul>
            @if ($claimable['total'] > $claimable['rooms']->count())
                <x-button label="Mostrar más" class="btn-ghost btn-sm" wire:click="moreClaims" spinner="moreClaims" data-testid="claim-more" />
            @endif
        @endif
    </div>

    <footer class="sticky bottom-0 flex flex-wrap items-center gap-2 border-t border-base-300 bg-base-100 px-5 py-3">
        <button type="button" class="link text-sm underline-offset-4" x-show="selected.length > 0" x-on:click="selected = []">Quitar la selección</button>
        <button type="button" class="btn btn-primary max-sm:h-11 ms-auto" x-bind:disabled="selected.length === 0 || claiming"
            x-on:click="claiming = true; $wire.claimRooms(selected).then(() => { selected = []; claiming = false }).catch(() => claiming = false)" data-testid="claim-confirm">
            <x-icon name="o-hand-raised" class="h-4 w-4" aria-hidden="true" />
            <span x-text="selected.length === 1 ? 'Reclamar 1 seleccionado' : 'Reclamar ' + selected.length + ' seleccionados'">Reclamar 0 seleccionados</span>
        </button>
    </footer>
</div>
