{{--
    Modalidades (U4 opción A «Catálogo junto a Campus»), con la anatomía de una pantalla de catálogo (DESIGN.md,
    "Layout"): encabezado y, en su renglón, la barra de herramientas común (x-core::catalog-toolbar: búsqueda, estado y
    una sola acción principal); tabla en una superficie blanca con filete, la clave en Mono, el perfil de flujo en una
    línea, el estado con su insignia y «Editar» visible y lo demás en «Acciones»; al pie, el rango y la paginación.
    Debajo de 768 px la tabla cede su lugar a renglones apilados, que no repiten los data-testid de la tabla. «Editar» y
    «Nueva modalidad» abren el panel lateral (core::modalities.profile-panel).
--}}
<div>
    <x-header title="Modalidades" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Cada modalidad con su perfil de flujo, que decide qué pasos del proceso aplican a su carga. La carga, sus coordinadores y la apertura del periodo son por modalidad; el campus es solo el lugar.
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar por clave o nombre"
                aria-label="Buscar modalidades"
                icon="o-magnifying-glass"
                clearable
            />
        </x-slot:search>

        @if ($canSeeInactive)
            <x-select
                wire:model.live="status"
                aria-label="Estado"
                :options="$statuses"
                placeholder="Activas e inactivas"
            />
        @else
            <span class="tooltip tooltip-bottom block" data-tip="Solo el administrador ve las modalidades inactivas.">
                <x-select aria-label="Estado" :options="[]" placeholder="Activas" disabled />
            </span>
        @endif

        <x-slot:action>
            @if ($canCreate)
                <x-button label="Nueva modalidad" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" data-testid="new-modality" />
            @else
                <span class="tooltip tooltip-left" data-tip="Solo el administrador da de alta modalidades.">
                    <x-button label="Nueva modalidad" icon="o-plus" class="btn-primary w-full" disabled />
                </span>
            @endif
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$modalities"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_code', $modality)
                <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $modality->is_active])>{{ $modality->code }}</span>
            @endscope

            @scope('cell_name', $modality)
                <span @class(['font-semibold' => $modality->is_active])>{{ $modality->name }}</span>
            @endscope

            @scope('cell_profile', $modality, $profiles)
                @if ($profiles[$modality->id] === null)
                    <x-core::status-badge tone="error" label="Perfil inválido" />
                @else
                    <span class="text-neutral">{{ $profiles[$modality->id] }}</span>
                @endif
            @endscope

            @scope('cell_is_active', $modality)
                <x-core::status-badge :tone="$modality->is_active ? 'success' : 'neutral'" :label="$modality->is_active ? 'Activa' : 'Inactiva'" />
            @endscope

            @scope('actions', $modality)
                @include('core::modalities.row-actions', ['modality' => $modality, 'inTable' => true])
            @endscope

            <x-slot:empty>
                <x-icon name="o-squares-2x2" label="Ninguna modalidad coincide con la búsqueda." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="modality-cards">
            @foreach ($modalities as $modality)
                <li wire:key="modality-card-{{ $modality->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $modality->is_active])>
                    <div class="flex items-center gap-2.5">
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $modality->is_active])>{{ $modality->code }}</span>
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $modality->is_active])>{{ $modality->name }}</span>

                        <x-core::status-badge :tone="$modality->is_active ? 'success' : 'neutral'" :label="$modality->is_active ? 'Activa' : 'Inactiva'" />
                    </div>

                    <div class="text-neutral">
                        @if ($profiles[$modality->id] === null)
                            <x-core::status-badge tone="error" label="Perfil inválido" />
                        @else
                            {{ $profiles[$modality->id] }}
                        @endif
                    </div>

                    @include('core::modalities.row-actions', ['modality' => $modality, 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($modalities->isNotEmpty() || $modalities->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($modalities->isNotEmpty())
                    <p>Modalidades <span class="font-mono">{{ $modalities->first()->code }}</span>@if ($modalities->count() > 1) a <span class="font-mono">{{ $modalities->last()->code }}</span> · <span class="font-mono">{{ $modalities->firstItem() }}–{{ $modalities->lastItem() }}</span>@else · <span class="font-mono">{{ $modalities->firstItem() }}</span>@endif de <span class="font-mono">{{ $modalities->total() }}</span></p>
                @endif

                {{ $modalities->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="modality-drawer-title" data-testid="modality-drawer">
        @if ($showDrawer)
            @include('core::modalities.profile-panel')
        @endif
    </x-drawer>
</div>
