{{--
    Anatomía de una pantalla de catálogo de un campus (DESIGN.md, "Layout"): encabezado y, en su renglón, la
    barra de herramientas común (x-core::catalog-toolbar: búsqueda, filtros de edificio, tipo, dueño y estado, y
    una sola acción principal); tabla en una superficie blanca con filete, la clave y la capacidad en Mono, el
    estado con su insignia y «Editar» visible con lo demás en el menú «Acciones» (entre ellas «Bloqueos…», que
    abre el panel lateral). Entre 768 y 1023 px se ocultan el edificio, el tipo y el dueño; debajo de 768 px,
    renglones apilados (clave, nombre y estado; luego el detalle; luego las acciones), que no repiten los
    data-testid de la tabla. Sin campus activo, en lugar de la tabla se pide elegir uno en la credencial.
--}}
<div>
    <x-header title="Salones" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Los salones del campus activo con su tipo, capacidad y departamento dueño. Un salón no se borra: se desactiva. Sus bloqueos (mantenimiento, reservas) se administran desde «Acciones».
        </x-slot:subtitle>
    </x-header>

    @if ($campus === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-6 py-10 text-center text-neutral" data-testid="rooms-no-campus">
            <x-icon name="o-building-office-2" class="mx-auto mb-2 w-8 h-8" />
            <p>Elige un campus en tu credencial para ver sus salones.</p>
        </div>
    @elseif (! $usesRooms)
        <div class="rounded-box border border-base-300 bg-base-100 px-6 py-10 text-center text-neutral" data-testid="rooms-not-used">
            <x-icon name="o-rectangle-stack" class="mx-auto mb-2 w-8 h-8" />
            <p>El campus {{ $campus->code }} no usa salones: sus grupos no se asignan a un salón.</p>
            <x-button label="Nuevo salón" icon="o-plus" class="btn-primary mt-4" disabled title="Este campus no usa salones." />
        </div>
    @else
        <x-core::catalog-toolbar narrow>
            <x-slot:search>
                <x-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Clave o nombre"
                    aria-label="Buscar salones"
                    icon="o-magnifying-glass"
                    clearable
                />
            </x-slot:search>

            <x-select wire:model.live="buildingFilter" aria-label="Edificio" :options="$buildings" placeholder="Edificio" />
            <x-select wire:model.live="typeFilter" aria-label="Tipo" :options="$types" placeholder="Tipo" />
            <x-select wire:model.live="ownerFilter" aria-label="Dueño" :options="$ownerFilters" placeholder="Dueño" />
            <x-select wire:model.live="status" aria-label="Estado" :options="$statuses" placeholder="Estado" />

            <x-slot:action>
                @if ($canCreate)
                    <x-button label="Nuevo salón" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" />
                @else
                    <x-button label="Nuevo salón" icon="o-plus" class="btn-primary" disabled title="Tu rol no administra este campus." />
                @endif
            </x-slot:action>
        </x-core::catalog-toolbar>

        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
            <x-table
                :headers="$headers"
                :rows="$rooms"
                :row-decoration="$rowDecoration"
                class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
            >
                @scope('cell_code', $room)
                    <span @class(['whitespace-nowrap font-mono text-[15px] tabular-nums', 'font-semibold' => $room->is_active])>{{ $room->code }}</span>
                @endscope

                @scope('cell_name', $room)
                    {{ $room->name ?? '—' }}
                @endscope

                @scope('cell_building', $room)
                    <span class="font-mono" title="{{ $room->building?->name }}">{{ $room->building?->code }}</span>
                @endscope

                @scope('cell_type', $room)
                    {{ $room->type->label() }}
                @endscope

                @scope('cell_capacity', $room)
                    <span class="font-mono tabular-nums">{{ $room->capacity }}</span>
                @endscope

                @scope('cell_department', $room)
                    @if ($room->department === null)
                        <span class="text-neutral">Sin dueño</span>
                    @else
                        <span class="font-mono" title="{{ $room->department->name }}">{{ $room->department->code }}</span>
                    @endif
                @endscope

                @scope('cell_blocks_count', $room)
                    <span class="font-mono tabular-nums">{{ $room->blocks_count }}</span>
                @endscope

                @scope('cell_is_active', $room)
                    <x-core::status-badge :tone="$room->is_active ? 'success' : 'neutral'" :label="$room->is_active ? 'Activo' : 'Inactivo'" />
                @endscope

                @scope('actions', $room)
                    @include('facilities::rooms.row-actions', ['room' => $room, 'inTable' => true])
                @endscope

                <x-slot:empty>
                    <x-icon name="o-rectangle-stack" label="Ningún salón coincide con la búsqueda." />
                </x-slot:empty>
            </x-table>

            <ul class="divide-y divide-base-300 md:hidden" data-testid="room-cards">
                @foreach ($rooms as $room)
                    <li wire:key="room-card-{{ $room->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $room->is_active])>
                        <div class="flex items-center gap-2.5">
                            <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $room->is_active])>{{ $room->code }}</span>
                            <span @class(['min-w-0 flex-1', 'font-semibold' => $room->is_active])>{{ $room->name ?? $room->type->label() }}</span>

                            <x-core::status-badge :tone="$room->is_active ? 'success' : 'neutral'" :label="$room->is_active ? 'Activo' : 'Inactivo'" />
                        </div>

                        <div class="text-neutral">
                            <span class="font-mono">{{ $room->building?->code }}</span> ·
                            {{ $room->type->label() }} ·
                            <span class="font-mono tabular-nums">{{ $room->capacity }}</span> lugares ·
                            @if ($room->department === null) Sin dueño @else <span class="font-mono">{{ $room->department->code }}</span> @endif
                            @if ($room->blocks_count > 0) · {{ trans_choice('{1} 1 bloqueo|[2,*] :count bloqueos', $room->blocks_count) }} @endif
                        </div>

                        @include('facilities::rooms.row-actions', ['room' => $room, 'inTable' => false])
                    </li>
                @endforeach
            </ul>

            @if ($rooms->isNotEmpty() || $rooms->hasPages())
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                    @if ($rooms->isNotEmpty())
                        <p>Salones <span class="font-mono">{{ $rooms->first()->code }}</span>@if ($rooms->count() > 1) a <span class="font-mono">{{ $rooms->last()->code }}</span> · <span class="font-mono">{{ $rooms->firstItem() }}–{{ $rooms->lastItem() }}</span>@else · <span class="font-mono">{{ $rooms->firstItem() }}</span>@endif de <span class="font-mono">{{ $rooms->total() }}</span> en <span class="font-mono">{{ $campus->code }}</span></p>
                    @endif

                    {{ $rooms->onEachSide(1)->links(data: ['scrollTo' => false]) }}
                </div>
            @endif
        </div>

        <x-modal wire:model="showModal" :title="$editingId === null ? 'Nuevo salón' : 'Editar salón'" box-class="max-w-2xl" separator>
            <x-form wire:submit="save" no-separator>
                <div class="grid gap-4 md:grid-cols-6">
                    <div class="md:col-span-3">
                        <x-select label="Edificio" name="building_id" wire:model="building_id" :options="$buildings" placeholder="Elige el edificio" />
                    </div>
                    <div class="md:col-span-3">
                        <x-input label="Clave" name="code" wire:model="code" placeholder="A-101" />
                    </div>

                    <div class="md:col-span-6">
                        <x-input label="Nombre (opcional)" name="name" wire:model="name" />
                    </div>

                    <div class="md:col-span-3">
                        <x-select label="Tipo" name="type" wire:model="type" :options="$types" placeholder="Elige el tipo" />
                    </div>
                    <div class="md:col-span-3">
                        <x-input label="Capacidad" name="capacity" wire:model="capacity" type="number" min="1" inputmode="numeric" />
                    </div>

                    <div class="md:col-span-6">
                        <x-select label="Departamento dueño" name="department_id" wire:model="department_id" :options="$ownerOptions" placeholder="Sin dueño" />
                    </div>

                    <div class="md:col-span-6">
                        <x-input label="Características" name="features" wire:model="features" placeholder="Proyector, aire acondicionado" hint="Sepáralas con comas." />
                    </div>
                </div>

                <x-slot:actions>
                    <x-button label="Cancelar" @click="$wire.showModal = false" />
                    <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
                </x-slot:actions>
            </x-form>
        </x-modal>

        {{-- Los atributos que no son de x-drawer caen en su panel (la x-card): ahí van la semántica de diálogo y el título que lo nombra. --}}
        <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="blocks-drawer-title">
            @if ($blocksRoom !== null)
                @include('facilities::rooms.blocks-panel')
            @endif
        </x-drawer>
    @endif
</div>
