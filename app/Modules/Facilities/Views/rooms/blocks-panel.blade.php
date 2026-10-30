{{--
    Los bloqueos de un salón en el panel lateral (facilities::pages.rooms; x-drawer a la derecha, a pantalla
    completa en celular): cada bloqueo con su día, su rango de horas, su periodo («Todos los periodos» si no
    tiene) y su motivo, con «Quitar»; debajo, el formulario para agregar otro. Los días del select son los del
    perfil del campus y las horas deben caer en su jornada (RoomRules). Sus campos se llaman como las claves de
    AddRoomBlock, así sus errores caen en su campo. `close()` es del x-drawer; el título lleva el id que nombra
    al diálogo (aria-labelledby).
--}}
@use('App\Modules\Facilities\Rules\RoomRules')
<div class="flex min-h-dvh flex-col" data-testid="blocks-drawer">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="blocks-drawer-title" class="text-xl font-bold leading-tight">Bloqueos de <span class="font-mono">{{ $blocksRoom->code }}</span></h2>
            <p class="mt-1 text-neutral">
                @if ($blocksRoom->name) {{ $blocksRoom->name }} · @endif
                Jornada de {{ $blocksRoom->campus->profile->dayStart }} a {{ $blocksRoom->campus->profile->dayEnd }}. Un bloqueo aparta el salón: el motor no lo asignará en ese rango.
            </p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-6 px-5 pt-4 pb-8">
        @if ($blocks->isEmpty())
            <p class="text-neutral" data-testid="no-blocks">Este salón no tiene bloqueos.</p>
        @else
            <ul class="divide-y divide-base-300 rounded-box border border-base-300">
                @foreach ($blocks as $block)
                    <li class="flex items-start gap-2 px-3.5 py-3" wire:key="block-{{ $block->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ ucfirst(RoomRules::DAYS[$block->day]) }} <span class="font-mono tabular-nums">{{ substr($block->starts_at, 0, 5) }} a {{ substr($block->ends_at, 0, 5) }}</span></p>
                            <p class="text-sm text-neutral">{{ $block->term === null ? 'Todos los periodos' : $block->term->name }} · {{ $block->reason }}</p>
                        </div>
                        <x-button
                            label="Quitar"
                            icon="o-trash"
                            class="btn-ghost btn-sm"
                            wire:click="removeBlock('{{ $block->id }}')"
                            wire:confirm="¿Quitar el bloqueo del {{ RoomRules::DAYS[$block->day] }} de {{ substr($block->starts_at, 0, 5) }} a {{ substr($block->ends_at, 0, 5) }}?"
                            spinner="removeBlock('{{ $block->id }}')"
                        />
                    </li>
                @endforeach
            </ul>
        @endif

        <x-form wire:submit="addBlock" no-separator class="space-y-1" data-testid="block-form">
            <h3 class="text-base font-semibold">Agregar un bloqueo</h3>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-select label="Día" name="day" wire:model="day" :options="$dayOptions" placeholder="Elige el día" />
                </div>
                <x-input label="Desde" name="starts_at" wire:model="starts_at" type="time" />
                <x-input label="Hasta" name="ends_at" wire:model="ends_at" type="time" />
                <div class="sm:col-span-2">
                    <x-select label="Periodo" name="term_id" wire:model="term_id" :options="$termOptions" placeholder="Todos los periodos" />
                </div>
                <div class="sm:col-span-2">
                    <x-input label="Motivo" name="reason" wire:model="reason" placeholder="Para qué se aparta el salón" />
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Agregar bloqueo" type="submit" icon="o-plus" class="btn-primary" spinner="addBlock" />
            </x-slot:actions>
        </x-form>

        <p class="text-sm text-neutral">Cada cambio queda en la bitácora.</p>
    </div>
</div>
