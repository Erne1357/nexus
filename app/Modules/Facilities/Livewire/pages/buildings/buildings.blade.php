{{--
    Anatomía de una pantalla de catálogo de un campus (DESIGN.md, "Layout"): encabezado y, en su renglón, la
    barra de herramientas común (x-core::catalog-toolbar); tabla en una superficie blanca con filete, la clave
    en Mono, el estado con su insignia y «Editar» visible con lo demás en el menú «Acciones»; al pie, el rango
    y la paginación. Debajo de 768 px, renglones apilados (clave, nombre y estado; luego el detalle; luego las
    acciones), que no repiten los data-testid de la tabla. Sin campus activo, en lugar de la tabla se pide
    elegir uno en la credencial.
--}}
<div>
    <x-header title="Edificios" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Los edificios del campus activo, donde están sus salones. Un edificio no se borra: se desactiva.
        </x-slot:subtitle>
    </x-header>

    @if ($campus === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-6 py-10 text-center text-neutral" data-testid="buildings-no-campus">
            <x-icon name="o-building-office-2" class="mx-auto mb-2 w-8 h-8" />
            <p>Elige un campus en tu credencial para ver sus edificios.</p>
        </div>
    @else
        <x-core::catalog-toolbar>
            <x-slot:search>
                <x-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar por clave o nombre"
                    aria-label="Buscar edificios"
                    icon="o-magnifying-glass"
                    clearable
                />
            </x-slot:search>

            <x-select wire:model.live="status" aria-label="Estado" :options="$statuses" placeholder="Activos e inactivos" />

            <x-slot:action>
                @if ($canCreate)
                    <x-button label="Nuevo edificio" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" />
                @else
                    <x-button label="Nuevo edificio" icon="o-plus" class="btn-primary" disabled title="Tu rol no administra este campus." />
                @endif
            </x-slot:action>
        </x-core::catalog-toolbar>

        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
            <x-table
                :headers="$headers"
                :rows="$buildings"
                :row-decoration="$rowDecoration"
                class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
            >
                @scope('cell_code', $building)
                    <span @class(['whitespace-nowrap font-mono text-[15px] tabular-nums', 'font-semibold' => $building->is_active])>{{ $building->code }}</span>
                @endscope

                @scope('cell_rooms_count', $building)
                    <span class="font-mono tabular-nums">{{ $building->rooms_count }}</span>
                @endscope

                @scope('cell_is_active', $building)
                    <x-core::status-badge :tone="$building->is_active ? 'success' : 'neutral'" :label="$building->is_active ? 'Activo' : 'Inactivo'" />
                @endscope

                @scope('actions', $building)
                    @include('facilities::buildings.row-actions', ['building' => $building, 'inTable' => true])
                @endscope

                <x-slot:empty>
                    <x-icon name="o-building-office" label="Ningún edificio coincide con la búsqueda." />
                </x-slot:empty>
            </x-table>

            <ul class="divide-y divide-base-300 md:hidden" data-testid="building-cards">
                @foreach ($buildings as $building)
                    <li wire:key="building-card-{{ $building->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $building->is_active])>
                        <div class="flex items-center gap-2.5">
                            <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $building->is_active])>{{ $building->code }}</span>
                            <span @class(['min-w-0 flex-1', 'font-semibold' => $building->is_active])>{{ $building->name }}</span>

                            <x-core::status-badge :tone="$building->is_active ? 'success' : 'neutral'" :label="$building->is_active ? 'Activo' : 'Inactivo'" />
                        </div>

                        <div class="text-neutral">{{ trans_choice('{0} Sin salones|{1} 1 salón|[2,*] :count salones', $building->rooms_count) }}</div>

                        @include('facilities::buildings.row-actions', ['building' => $building, 'inTable' => false])
                    </li>
                @endforeach
            </ul>

            @if ($buildings->isNotEmpty() || $buildings->hasPages())
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                    @if ($buildings->isNotEmpty())
                        <p>Edificios <span class="font-mono">{{ $buildings->first()->code }}</span>@if ($buildings->count() > 1) a <span class="font-mono">{{ $buildings->last()->code }}</span> · <span class="font-mono">{{ $buildings->firstItem() }}–{{ $buildings->lastItem() }}</span>@else · <span class="font-mono">{{ $buildings->firstItem() }}</span>@endif de <span class="font-mono">{{ $buildings->total() }}</span> en <span class="font-mono">{{ $campus->code }}</span></p>
                    @endif

                    {{ $buildings->onEachSide(1)->links(data: ['scrollTo' => false]) }}
                </div>
            @endif
        </div>

        <x-modal wire:model="showModal" :title="$editingId === null ? 'Nuevo edificio' : 'Editar edificio'" box-class="max-w-lg" separator>
            <x-form wire:submit="save" no-separator>
                <div class="grid gap-4 md:grid-cols-6">
                    <div class="md:col-span-2">
                        <x-input label="Clave" name="code" wire:model="code" placeholder="A" />
                    </div>
                    <div class="md:col-span-4">
                        <x-input label="Nombre" name="name" wire:model="name" placeholder="Edificio A" />
                    </div>
                </div>

                <x-slot:actions>
                    <x-button label="Cancelar" @click="$wire.showModal = false" />
                    <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
                </x-slot:actions>
            </x-form>
        </x-modal>
    @endif
</div>
