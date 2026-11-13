{{--
    Anatomía de una pantalla de catálogo (DESIGN.md, "Layout"): encabezado y, en su renglón, la barra de herramientas común (x-core::catalog-toolbar: búsqueda,
    filtros y una sola acción principal); tabla en una superficie blanca con filete, la clave en Mono, el estado con su
    insignia y «Editar» visible y lo demás en el menú «Acciones»; al pie, el rango y la paginación. Debajo de 768 px la tabla
    cede su lugar a renglones apilados (clave, nombre y estado; luego el detalle; luego las acciones),
    que no repiten los data-testid de la tabla.
--}}
<div>
    <x-header title="Campus" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Los lugares físicos del Tec: cada edificio y cada salón está en un campus. Qué se trabaja y cómo, está en Modalidades.
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar por código o nombre"
                aria-label="Buscar campus"
                icon="o-magnifying-glass"
                clearable
            />
        </x-slot:search>

        @if ($canSeeInactive)
            <x-select
                wire:model.live="status"
                aria-label="Estado"
                :options="$statuses"
                placeholder="Activos e inactivos"
            />
        @else
            <span class="tooltip tooltip-bottom block" data-tip="Solo el administrador ve los campus inactivos.">
                <x-select aria-label="Estado" :options="[]" placeholder="Activos" disabled />
            </span>
        @endif

        <x-slot:action>
            @if ($canCreate)
                <x-button label="Nuevo campus" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" />
            @else
                <span class="tooltip tooltip-left" data-tip="Solo el administrador da de alta campus.">
                    <x-button label="Nuevo campus" icon="o-plus" class="btn-primary w-full" disabled />
                </span>
            @endif
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$campuses"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_code', $campus)
                <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $campus->is_active])>{{ $campus->code }}</span>
            @endscope

            @scope('cell_is_active', $campus)
                <x-core::status-badge :tone="$campus->is_active ? 'success' : 'neutral'" :label="$campus->is_active ? 'Activo' : 'Inactivo'" />
            @endscope

            @scope('actions', $campus)
                @include('core::campuses.row-actions', ['campus' => $campus, 'inTable' => true])
            @endscope

            <x-slot:empty>
                <x-icon name="o-building-office-2" label="Ningún campus coincide con la búsqueda." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="campus-cards">
            @foreach ($campuses as $campus)
                <li wire:key="campus-card-{{ $campus->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $campus->is_active])>
                    <div class="flex items-center gap-2.5">
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $campus->is_active])>{{ $campus->code }}</span>
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $campus->is_active])>{{ $campus->name }}</span>

                        <x-core::status-badge :tone="$campus->is_active ? 'success' : 'neutral'" :label="$campus->is_active ? 'Activo' : 'Inactivo'" />
                    </div>

                    @include('core::campuses.row-actions', ['campus' => $campus, 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($campuses->isNotEmpty() || $campuses->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($campuses->isNotEmpty())
                    <p>Campus <span class="font-mono">{{ $campuses->first()->code }}</span>@if ($campuses->count() > 1) a <span class="font-mono">{{ $campuses->last()->code }}</span> · <span class="font-mono">{{ $campuses->firstItem() }}–{{ $campuses->lastItem() }}</span>@else · <span class="font-mono">{{ $campuses->firstItem() }}</span>@endif de <span class="font-mono">{{ $campuses->total() }}</span></p>
                @endif

                {{ $campuses->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <p class="mt-4 flex items-start gap-2.5 rounded-box border border-base-300 bg-base-100 px-4 py-3 text-sm" data-testid="campus-note">
        <x-icon name="o-information-circle" class="mt-0.5 h-5 w-5 shrink-0 text-neutral" aria-hidden="true" />
        <span>El formulario de un campus pide solo código y nombre. La modalidad y el perfil de flujo se administran en <a href="{{ route('core.modalities') }}" wire:navigate class="font-medium text-primary underline">Modalidades</a>.</span>
    </p>

    <x-modal wire:model="showModal" :title="$editingId === null ? 'Nuevo campus' : 'Editar campus'" box-class="max-w-xl" separator>
        <x-form wire:submit="save" no-separator>
            <div class="grid gap-4 md:grid-cols-2">
                <x-input label="Código" name="code" wire:model="code" placeholder="C3" />
                <x-input label="Nombre" name="name" wire:model="name" />
            </div>

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
