{{--
    Salón de la sesión, dentro del cajón de la sesión del editor (maqueta `.impeccable/mocks/horarios/cajones.html`,
    estado `#sesion`):
    - «Salón actual»: clave en Mono, tipo y su estado («Asignado», o los choques que deja: «Préstamo pendiente · HC-10»…)
      con el detalle en renglones que parten; «Sin salón» en advertencia si no tiene. Quien asigna lo puede quitar.
    - «Salones sugeridos»: un radio por salón (scheduling::session-room.row), del mejor al peor; el buscador de otro
      salón con sus resultados aparte (los sugeridos que coinciden se resaltan en su lista) y, al elegir uno que
      causaría choques, lo que pasaría al guardarlo. La elección vive en Alpine (`selected`) hasta «Asignar».
    - Al pie, «Asignar salón» o «Asignar y pedir préstamo». Sin permiso o con el grupo sin editar, lo mismo en solo
      lectura y con su motivo.
    El editor lo incrusta con `wire:key="room-{sessionId}-{updated_at}"` para volver a montarlo cuando la sesión cambia.
--}}
<section aria-labelledby="session-room-title-{{ $sessionId }}" class="grid min-w-0 gap-5" data-testid="session-room"
    x-data="{ selected: '', loanRooms: @js($loanRooms) }"
    x-on:session-room-saved.window="if ($event.detail.sessionId === @js($sessionId)) selected = ''">
    @php($alerts = collect($errors->getBag('default')->toArray())->except(['roomId'])->flatten()->unique()->values())
    @if ($alerts->isNotEmpty())
        <div role="alert" class="grid gap-1 rounded-field border border-error/40 bg-error/10 px-3 py-2 text-sm" data-testid="session-room-error">
            @foreach ($alerts as $message)
                <span class="flex items-start gap-2 break-words"><x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0 text-error" aria-hidden="true" /><span class="min-w-0">{{ $message }}</span></span>
            @endforeach
        </div>
    @endif

    <div class="grid gap-2">
        <h3 id="session-room-title-{{ $sessionId }}" class="text-base font-semibold">Salón actual</h3>
        <div class="grid gap-1.5 rounded-box border border-base-300 bg-base-200 px-3 py-2.5" data-testid="current-room">
            @if ($current)
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                    <span class="font-mono text-base font-semibold">{{ $current['code'] }}</span>
                    <span>{{ $current['type'] }}</span>
                    @forelse ($current['conflicts'] as $conflict)
                        <x-core::status-badge tone="error" :label="$conflict['label'].' · '.$conflict['rule']" :icon="$conflict['rule'] === 'HC-10' ? 'o-clock' : null" />
                    @empty
                        <x-core::status-badge tone="success" label="Asignado" />
                    @endforelse
                    @if ($canAssign)
                        <x-button wire:click="removeRoom" label="Quitar salón" icon="o-x-mark" class="btn-ghost btn-sm ms-auto" spinner="removeRoom" />
                    @endif
                </div>
                @foreach ($current['conflicts'] as $conflict)
                    <p class="text-sm break-words"><b class="font-mono">{{ $conflict['rule'] }}</b> {{ $conflict['message'] }}</p>
                @endforeach
            @else
                <div><x-core::status-badge tone="warning" label="Sin salón" icon="o-exclamation-triangle" /></div>
            @endif
        </div>
    </div>

    <div class="grid min-w-0 gap-2">
        <h3 class="text-base font-semibold">Salones sugeridos</h3>
        <div role="radiogroup" aria-label="Salones sugeridos" class="grid gap-2" data-testid="room-suggestions">
            @forelse ($rooms as $row)
                @include('scheduling::session-room.row', ['row' => $row, 'list' => 'suggested', 'matched' => in_array($row['id'], $matched, true)])
            @empty
                <p class="text-sm text-neutral">El campus no tiene salones activos.</p>
            @endforelse
        </div>

        <x-input wire:model.live.debounce.400ms="search" icon="o-magnifying-glass" placeholder="Buscar otro salón" aria-label="Buscar otro salón" class="mt-2" clearable />

        @if ($searched)
            <div role="radiogroup" aria-label="Resultados de la búsqueda" class="grid gap-2" data-testid="room-results">
                @foreach ($results as $row)
                    @include('scheduling::session-room.row', ['row' => $row, 'list' => 'results', 'matched' => false])
                @endforeach
                @if ($nothingFound)
                    <p class="flex items-start gap-2 rounded-field border border-info/40 bg-info/10 px-3 py-2 text-sm break-words"><x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" /><span class="min-w-0">Sin resultados para «{{ trim($search) }}».</span></p>
                @elseif ($results === [])
                    <p class="text-sm text-neutral break-words" data-testid="room-results-in-suggestions">«{{ trim($search) }}» ya está entre los sugeridos, resaltado arriba.</p>
                @endif
            </div>
        @endif

        @error('roomId') <p class="text-sm text-error" role="alert">{{ $message }}</p> @enderror
    </div>

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-base-300 pt-3">
        @if ($reason)
            <span class="flex min-w-0 items-start gap-1.5 text-sm text-neutral break-words" data-testid="session-room-reason"><x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span class="min-w-0">{{ $reason }}</span></span>
        @endif
        <button type="button" class="btn btn-primary" data-testid="session-room-assign"
            @disabled(! $canAssign) @if ($canAssign) x-bind:disabled="selected === ''" x-on:click="$wire.assign(selected)" @endif
            wire:loading.attr="disabled" wire:target="assign">
            <span wire:loading wire:target="assign" class="loading loading-spinner loading-sm"></span>
            <span x-show="! loanRooms.includes(selected)">Asignar salón</span>
            <span x-show="loanRooms.includes(selected)" x-cloak>Asignar y pedir préstamo</span>
        </button>
    </div>
</section>
