{{--
    La lista de permisos con casillas de los drawers de core::pages.permissions («Agregar permiso suelto» y «Editar
    rol»): un buscador por etiqueta o clave, «Todos / Marcados» y los permisos por grupo, cada grupo plegable con una
    casilla que marca o desmarca lo que se ve y se puede marcar (toggleGroup) y su cifra. Lo que no se puede marcar va
    deshabilitado con su motivo escrito (CF-03, CF-04, CF-S2) o con «Ya lo tiene: …». Espera `$groups`, `$target`
    (`loose` o `editor`) y `$model` (la propiedad de las casillas).
--}}
<div class="space-y-2.5">
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="min-w-52 flex-1">
            <x-input wire:model.live.debounce.300ms="pickerSearch" placeholder="Busca un permiso o su clave" aria-label="Buscar permiso" icon="o-magnifying-glass" clearable />
        </div>
        <div class="join max-md:grid max-md:w-full max-md:grid-cols-2" role="group" aria-label="Mostrar">
            <button type="button" wire:click="$set('pickerMarked', false)" aria-pressed="{{ $pickerMarked ? 'false' : 'true' }}" @class(['btn join-item max-md:min-h-11', 'btn-primary' => ! $pickerMarked])>Todos</button>
            <button type="button" wire:click="$set('pickerMarked', true)" aria-pressed="{{ $pickerMarked ? 'true' : 'false' }}" @class(['btn join-item max-md:min-h-11', 'btn-primary' => $pickerMarked])>Marcados</button>
        </div>
    </div>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100" wire:loading.class="opacity-60" wire:target="pickerSearch,pickerMarked,toggleGroup">
        @forelse ($groups as $group)
            <div wire:key="{{ $target }}-group-{{ $group['id'] }}-{{ md5($pickerSearch.'|'.($pickerMarked ? 1 : 0)) }}" x-data="{ open: @js($group['open']) }" class="border-t border-base-300 first:border-t-0">
                <div class="flex min-h-11 items-center gap-2.5 bg-secondary/40 ps-3.5">
                    <input
                        type="checkbox"
                        class="checkbox checkbox-sm"
                        wire:key="{{ $target }}-toggle-{{ $group['id'] }}-{{ $group['all'] ? 1 : 0 }}{{ $group['some'] ? 1 : 0 }}"
                        wire:click="toggleGroup('{{ $target }}', '{{ $group['id'] }}')"
                        @checked($group['all'])
                        @disabled(! $group['toggleable'])
                        x-init="$el.indeterminate = @js($group['some'])"
                        aria-label="Marcar todo {{ $group['label'] }}"
                    />
                    <button type="button" class="flex min-h-11 min-w-0 flex-1 items-center gap-2.5 pe-3.5 text-start font-semibold" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'">
                        <span class="min-w-0 flex-1">{{ $group['label'] }}</span>
                        <span class="font-mono text-[12.5px] font-semibold text-neutral">{{ $group['count'] }}</span>
                        <x-icon name="o-chevron-down" class="h-4 w-4 shrink-0 text-neutral transition-transform motion-reduce:transition-none" x-bind:class="open && 'rotate-180'" aria-hidden="true" />
                    </button>
                </div>

                <div x-show="open" @unless ($group['open']) x-cloak @endunless>
                    @foreach ($group['rows'] as $row)
                        <label
                            wire:key="{{ $target }}-row-{{ $row['key'] }}"
                            data-testid="{{ $target }}-row-{{ $row['key'] }}"
                            @class([
                                'flex items-start gap-3 border-t border-base-300 py-2.5 pe-3.5 ps-10 max-md:ps-3.5',
                                'cursor-pointer hover:bg-base-200' => ! $row['disabled'],
                                'cursor-not-allowed' => $row['disabled'],
                            ])
                        >
                            @if ($row['disabled'])
                                <input type="checkbox" class="checkbox checkbox-sm mt-0.5" @checked($row['checked']) disabled />
                            @else
                                <input type="checkbox" class="checkbox checkbox-sm mt-0.5" wire:model.live="{{ $model }}" value="{{ $row['key'] }}" />
                            @endif
                            <span class="grid min-w-0 gap-px">
                                <span @class(['text-neutral' => $row['disabled'] && ! $row['checked']])>{{ $row['label'] }}</span>
                                <span class="break-all font-mono text-[12.5px] text-neutral">{{ $row['key'] }}</span>
                                @if ($row['problem'] !== null)
                                    <span class="mt-0.5 flex items-start gap-1.5 text-[12.5px] text-neutral">
                                        <x-icon :name="$row['locked'] ? 'o-lock-closed' : 'o-no-symbol'" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        <span>{{ $row['problem'] }}</span>
                                    </span>
                                @endif
                                @if ($row['note'] !== null)
                                    <span class="mt-0.5 text-[12.5px] text-neutral">{{ $row['note'] }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="px-4 py-4 text-sm text-neutral">Ningún permiso coincide.</p>
        @endforelse
    </div>
</div>
