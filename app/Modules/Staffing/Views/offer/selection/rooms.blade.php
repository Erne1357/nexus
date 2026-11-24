{{--
    «Salón» del drawer del grupo en su turno (maqueta B, «b-drawer» y «b-mapa»): la regla de capacidad en una línea y las
    pestañas Lista y Mapa. Lista: «Se pueden elegir» con radio, tipo, lugares, edificio y cuánto le sobra; «No se pueden
    elegir» en gris con su motivo (SEL-01 a SEL-04). Mapa: `scheduling::components.map-picker` en modo «elegir» si existe
    (se monta solo con esta pestaña y una vez por grupo: pesa) o un aviso neutro; debajo, los elegibles que no están en
    el mapa y el salón elegido. Sin ningún elegible, el aviso de que se toma sin salón.
    Espera $card, $options, $chosen, $mapPicker, $roomTab y $space.
--}}
@use('App\Modules\Facilities\Enums\RoomType')
@php
    $limited = $card['required_space'] !== 'aula';
    $fit = function (array $room) use ($card, $limited): string {
        $slack = $room['capacity'] - $card['capacity'];

        return match (true) {
            $slack === 0 => 'Le queda justo',
            $limited && $slack > 10 => 'El más chico libre que le cabe',
            default => "Sobran {$slack}",
        };
    };
    $typeLabel = fn (string $type): string => RoomType::tryFrom($type)?->label() ?? $type;
@endphp
<section class="space-y-3" aria-labelledby="selection-room-title" data-testid="selection-rooms">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 id="selection-room-title" class="text-base font-bold">Salón</h3>
        <p class="text-[12.5px] text-neutral">
            {{ $space['label'] }} para <span class="font-mono">{{ $card['capacity'] }}</span>:
            @if ($limited)
                de <span class="font-mono">{{ $card['capacity'] }}</span> a <span class="font-mono">{{ $card['capacity'] + 10 }}</span> lugares
            @else
                <span class="font-mono">{{ $card['capacity'] }}</span> lugares o más
            @endif
        </p>
    </div>

    @if ($options['eligible'] === [])
        <div role="note" class="alert alert-warning alert-soft items-start text-sm" data-testid="selection-no-rooms">
            <x-icon name="o-exclamation-triangle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
            <div>
                <p class="font-semibold">No queda ningún salón que se pueda elegir</p>
                <p>Puedes tomarlo sin salón; lo asigna la jefatura.</p>
            </div>
        </div>
    @else
        <div role="tablist" aria-label="Cómo eliges el salón" class="tabs tabs-border">
            @foreach (['lista' => ['o-list-bullet', 'Lista'], 'mapa' => ['o-map', 'Mapa']] as $tab => [$icon, $label])
                <button type="button" role="tab" aria-selected="{{ $roomTab === $tab ? 'true' : 'false' }}" wire:click="$set('roomTab', '{{ $tab }}')"
                    @class(['tab gap-1.5 max-md:h-11', 'tab-active font-semibold' => $roomTab === $tab]) data-testid="selection-room-tab-{{ $tab }}">
                    <x-icon :name="$icon" class="h-4 w-4" aria-hidden="true" />{{ $label }}
                </button>
            @endforeach
        </div>

        @if ($roomTab === 'mapa')
            <div class="space-y-3" role="tabpanel" aria-label="Mapa">
                @if ($mapPicker)
                    <livewire:scheduling::components.map-picker
                        :eligible="array_values(array_filter(array_column($options['eligible'], 'map_key')))"
                        :rejected="collect($options['rejected'])->filter(fn ($room) => $room['map_key'] !== null)->mapWithKeys(fn ($room) => [$room['map_key'] => $room['reason']])->all()"
                        :campus-id="$card['campus_id']"
                        wire:key="map-picker-{{ $card['group_id'] }}" />
                @else
                    <div class="flex items-center gap-3 rounded-field border border-dashed border-base-300 px-4 py-5 text-sm text-neutral" data-testid="selection-map-placeholder">
                        <x-icon name="o-map" class="h-5 w-5 flex-none" aria-hidden="true" />
                        <p>El mapa aún no está disponible. Elige el salón en la lista.</p>
                    </div>
                @endif

                @php($offMap = array_values(array_filter($options['eligible'], fn ($room) => $room['map_key'] === null)))
                @if ($offMap !== [])
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Fuera del mapa</span>
                        @foreach ($offMap as $room)
                            <button type="button" wire:key="offmap-{{ $room['id'] }}" wire:click="$set('roomId', '{{ $room['id'] }}')" aria-pressed="{{ $chosen && $chosen['id'] === $room['id'] ? 'true' : 'false' }}"
                                @class(['btn btn-sm max-md:h-11', 'btn-primary' => $chosen && $chosen['id'] === $room['id']])>
                                <span class="font-mono">{{ $room['code'] }}</span>@if ($room['building']) <span class="text-[12.5px] opacity-80">{{ $room['building'] }}</span>@endif
                            </button>
                        @endforeach
                    </div>
                @endif

                @if ($chosen)
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" data-testid="selection-room-chosen">
                        <x-icon name="o-map-pin" class="h-4 w-4 text-neutral" aria-hidden="true" />
                        <b class="font-mono">{{ $chosen['code'] }}</b>
                        <span>{{ $typeLabel($chosen['type']) }} · <span class="font-mono">{{ $chosen['capacity'] }}</span> lugares @if ($chosen['building']) · edificio {{ $chosen['building'] }}@endif</span>
                        <span class="badge badge-success badge-soft badge-sm">{{ $fit($chosen) }}</span>
                    </p>
                @endif
            </div>
        @else
            <div class="space-y-3" role="tabpanel" aria-label="Lista">
                <fieldset>
                    <legend class="mb-1.5 flex w-full items-center gap-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Se pueden elegir <span class="font-mono">{{ count($options['eligible']) }}</span></legend>
                    <ul class="divide-y divide-base-300 overflow-hidden rounded-field border border-base-300">
                        @foreach ($options['eligible'] as $room)
                            <li wire:key="room-{{ $room['id'] }}">
                                <label @class(['flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-base-200', 'bg-secondary/60' => $chosen && $chosen['id'] === $room['id']])>
                                    <input type="radio" class="radio radio-sm" name="selection-room" value="{{ $room['id'] }}" wire:model.live="roomId" />
                                    <b class="w-20 flex-none font-mono">{{ $room['code'] }}</b>
                                    <span class="min-w-0 flex-1">
                                        <span class="block">{{ $typeLabel($room['type']) }} · <span class="font-mono">{{ $room['capacity'] }}</span> lugares</span>
                                        @if ($room['building'])
                                            <span class="block text-[12.5px] text-neutral">Edificio {{ $room['building'] }}</span>
                                        @endif
                                    </span>
                                    <span class="badge badge-success badge-soft badge-sm whitespace-nowrap">{{ $fit($room) }}</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </fieldset>
                @if ($options['rejected'] !== [])
                    <div>
                        <p class="mb-1.5 flex items-center gap-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">No se pueden elegir <span class="font-mono">{{ count($options['rejected']) }}</span></p>
                        <ul class="divide-y divide-base-300 overflow-hidden rounded-field border border-base-300 text-neutral">
                            @foreach ($options['rejected'] as $room)
                                <li wire:key="rejected-{{ $room['id'] }}" class="flex items-center gap-3 px-3 py-2.5">
                                    <span class="h-4 w-4 flex-none" aria-hidden="true"></span>
                                    <b class="w-20 flex-none font-mono">{{ $room['code'] }}</b>
                                    <span class="min-w-0 flex-1">
                                        <span class="block">{{ $typeLabel($room['type']) }} · <span class="font-mono">{{ $room['capacity'] }}</span> lugares</span>
                                        @if ($room['building'])
                                            <span class="block text-[12.5px]">Edificio {{ $room['building'] }}</span>
                                        @endif
                                    </span>
                                    <span class="inline-flex items-center gap-1 text-end text-[12.5px] font-medium"><x-icon name="o-no-symbol" class="h-3.5 w-3.5 flex-none" aria-hidden="true" />{{ $room['reason'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    @endif
    @error('room')
        <p class="text-sm font-semibold text-error" role="alert">{{ $message }}</p>
    @enderror
</section>
