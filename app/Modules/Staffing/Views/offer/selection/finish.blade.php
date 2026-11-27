{{--
    «¿Terminar tu carga?» (x-modal; maqueta B, «b-dentro», «b-fuera» y «b-mas»; SEL-05): sus horas, sus grupos con su
    horario y su salón; un grupo sin salón dice «Este grupo queda sin salón; lo asigna la jefatura» (se puede terminar
    así). Dentro de la banda, el aviso en `success`. Fuera, el aviso en `warning`, la casilla obligatoria y qué pasa:
    con menos horas, la ronda extra; con más, que quedan justificadas. El botón espera la casilla.
    Con la selección terminada es «¿Confirmar tu carga?» (SEL-14): lo mismo, solo lectura de sus grupos, y confirma con lo
    que lleva (con menos horas ya no hay ronda).
    Espera $data, $band y $selection.
--}}
@use('App\Modules\Staffing\Rules\LoadBand')
@php
    $hours = $data['my_hours'];
    $base = $data['base_hours'];
    $administrative = $data['administrative_hours'];
    $cap = $data['administrative_cap'];
    $low = $base - LoadBand::MARGIN;
    $high = $base + LoadBand::MARGIN;
    $out = $band !== null && $band !== 'within';
    $groups = $data['my_groups'];
    $confirming = $selection->status === \App\Modules\Staffing\Enums\SelectionStatus::Finished;
@endphp
<x-modal wire:model="showFinish" :title="$confirming ? '¿Confirmar tu carga?' : '¿Terminar tu carga?'" box-class="max-w-xl" data-testid="selection-finish-modal">
    <div class="space-y-3">
        @if ($administrative !== null)
            <p>Llevas <b class="font-mono">{{ $hours }}</b> h en {{ count($groups) }} {{ count($groups) === 1 ? 'grupo' : 'grupos' }} · plaza administrativa: <b class="font-mono">{{ $administrative }}</b> h ({{ $hours + $administrative }} de {{ $cap }} h).</p>
        @else
            <p>Llevas <b class="font-mono">{{ $hours }}</b> h en {{ count($groups) }} {{ count($groups) === 1 ? 'grupo' : 'grupos' }} · frente a grupo: <b class="font-mono">{{ $base }}</b> h ({{ $low }} a {{ $high }}).</p>
        @endif

        @if ($groups !== [])
            <ul class="divide-y divide-base-300 overflow-hidden rounded-field border border-base-300">
                @foreach ($groups as $group)
                    <li wire:key="finish-{{ $group['group_id'] }}" class="px-3 py-2">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                            <b class="font-mono whitespace-nowrap">{{ $group['subject_code'] }} {{ $group['group_code'] }}</b>
                            <span class="min-w-0 flex-1 truncate">{{ $group['subject_name'] }}</span>
                            <span class="font-mono text-[12.5px] whitespace-nowrap">{{ $group['schedule'] }}</span>
                            <span @class(['font-mono text-[12.5px] whitespace-nowrap', 'font-semibold text-warning' => $group['rooms'] === []])>{{ $group['rooms'] === [] ? 'sin salón' : implode(', ', $group['rooms']) }}</span>
                        </div>
                        @if ($group['rooms'] === [])
                            <p class="mt-1 flex items-center gap-1 text-[12.5px] font-semibold text-warning"><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5" aria-hidden="true" />Este grupo queda sin salón; lo asigna la jefatura</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-neutral">Todavía no tomas ningún grupo.</p>
        @endif

        @if ($out)
            <div role="note" class="alert alert-warning alert-soft items-start text-sm" data-testid="selection-finish-out">
                <x-icon name="o-exclamation-triangle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                <div>
                    <p class="font-semibold">{{ $band === 'under' ? 'Te faltan '.($low - $hours).' h para tu margen' : 'Pasas tu margen por '.($hours - $high).' h' }}</p>
                    <p>Es responsabilidad del docente cubrir sus horas frente a grupo de acuerdo con su plaza.</p>
                </div>
            </div>
            <x-checkbox wire:model.live="acknowledged" label="Entiendo que es mi responsabilidad cubrir mis horas frente a grupo de acuerdo con mi plaza." class="checkbox-primary" data-testid="selection-acknowledge" />
            @error('acknowledged')
                <p class="text-sm font-semibold text-error" role="alert">{{ $message }}</p>
            @enderror
            <p class="flex gap-2 text-sm text-neutral">
                @if ($band === 'under' && $confirming)
                    <x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" /><span>La selección ya terminó: confirmas con las horas que llevas.</span>
                @elseif ($band === 'under' && $selection->status !== \App\Modules\Staffing\Enums\SelectionStatus::ExtraRound)
                    <x-icon name="o-arrow-path" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" /><span>Con menos horas pasas a la <b>ronda extra</b>: cuando termine la primera ronda, en el mismo orden, eliges otra vez de lo que quede.</span>
                @elseif ($band === 'under')
                    <x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" /><span>Es la ronda extra: si terminas con menos horas, ya no hay otra ronda.</span>
                @else
                    <x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" /><span>Las horas de más quedan justificadas con esta confirmación.</span>
                @endif
            </p>
        @else
            <div role="note" class="alert alert-success alert-soft items-start text-sm" data-testid="selection-finish-within">
                <x-icon name="o-check-circle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                <div>
                    <p class="font-semibold">{{ $administrative !== null ? 'Tu plaza administrativa no lleva margen' : 'Estás dentro de tu margen' }}</p>
                    <p>{{ $confirming ? 'Al confirmar' : 'Al terminar' }}, tus grupos quedan a tu nombre y ya no los cambias en la selección.</p>
                </div>
            </div>
        @endif
    </div>
    <x-slot:actions>
        @if ($confirming)
            <x-button label="Volver" class="btn-ghost" @click="$wire.showFinish = false" />
            <x-button label="Confirmar mi carga" icon="o-check" class="btn-primary" wire:click="confirm" spinner="confirm" :disabled="$out && ! $acknowledged" data-testid="selection-confirm-submit" />
        @else
            <x-button label="Seguir eligiendo" class="btn-ghost" @click="$wire.showFinish = false" />
            <x-button label="Terminar mi carga" icon="o-check" class="btn-primary" wire:click="finish" spinner="finish" :disabled="$out && ! $acknowledged" data-testid="selection-finish-confirm" />
        @endif
    </x-slot:actions>
</x-modal>
