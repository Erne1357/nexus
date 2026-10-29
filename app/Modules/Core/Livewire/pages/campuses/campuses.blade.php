{{--
    Anatomía de una pantalla de catálogo (DESIGN.md, "Layout"): encabezado con búsqueda, filtro y una
    sola acción principal; tabla en una superficie blanca con filete, la clave en Mono, el estado con su
    insignia y las acciones en btn-ghost; al pie, el rango y la paginación. Debajo de 768 px la tabla
    cede su lugar a renglones apilados (clave, nombre y estado; luego el detalle; luego las acciones),
    que no repiten los data-testid de la tabla.
--}}
<div>
    <x-header title="Campus" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Cada campus con su modalidad y su perfil de flujo, que decide qué pasos del proceso aplican.
        </x-slot:subtitle>

        <x-slot:actions class="w-full flex-wrap md:w-auto">
            <div class="w-full md:w-64">
                <x-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar por código o nombre"
                    aria-label="Buscar campus"
                    icon="o-magnifying-glass"
                    clearable
                />
            </div>

            <div class="flex-1 md:w-44 md:flex-none">
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
            </div>

            @if ($canCreate)
                <x-button label="Nuevo campus" icon="o-plus" class="btn-primary max-md:flex-1" wire:click="create" spinner="create" />
            @else
                <span class="tooltip tooltip-left max-md:flex-1" data-tip="Solo el administrador da de alta campus.">
                    <x-button label="Nuevo campus" icon="o-plus" class="btn-primary w-full" disabled />
                </span>
            @endif
        </x-slot:actions>
    </x-header>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$campuses"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_th]:text-xs [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-widest [&_th]:text-neutral [&_thead]:bg-secondary/40"
        >
            @scope('cell_code', $campus)
                <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $campus->is_active])>{{ $campus->code }}</span>
            @endscope

            @scope('cell_modality', $campus)
                {{ $campus->modality->label() }}
            @endscope

            @scope('cell_profile', $campus, $profiles)
                @if ($profiles[$campus->id] === null)
                    <x-core::status-badge tone="error" label="Perfil inválido" />
                @else
                    <span class="text-neutral">{{ $profiles[$campus->id] }}</span>
                @endif
            @endscope

            @scope('cell_is_active', $campus)
                @if ($campus->is_active)
                    <x-core::status-badge tone="success" label="Activo" />
                @else
                    <x-core::status-badge tone="neutral" label="Inactivo" />
                @endif
            @endscope

            @scope('actions', $campus)
                <div class="flex justify-end gap-1">
                    <x-button
                        icon="o-pencil-square"
                        wire:click="edit('{{ $campus->id }}')"
                        aria-label="Editar {{ $campus->code }}"
                        data-testid="edit-{{ $campus->code }}"
                        class="btn-ghost btn-sm"
                        spinner="edit('{{ $campus->id }}')"
                    >
                        <span class="max-[1200px]:hidden">Editar</span>
                    </x-button>

                    @can('update', $campus)
                        <x-button
                            :label="$campus->is_active ? 'Desactivar' : 'Activar'"
                            wire:click="toggleActive('{{ $campus->id }}')"
                            wire:confirm="{{ $campus->is_active ? '¿Desactivar el campus '.$campus->code.'? Nadie podrá trabajar en él hasta que se reactive.' : '¿Activar el campus '.$campus->code.'?' }}"
                            class="btn-ghost btn-sm"
                            spinner="toggleActive('{{ $campus->id }}')"
                        />
                    @else
                        <span class="tooltip tooltip-left" data-tip="Solo el administrador activa o desactiva campus.">
                            <x-button :label="$campus->is_active ? 'Desactivar' : 'Activar'" class="btn-ghost btn-sm" disabled />
                        </span>
                    @endcan
                </div>
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

                        @if ($campus->is_active)
                            <x-core::status-badge tone="success" label="Activo" />
                        @else
                            <x-core::status-badge tone="neutral" label="Inactivo" />
                        @endif
                    </div>

                    <div class="text-neutral">
                        {{ $campus->modality->label() }} ·
                        @if ($profiles[$campus->id] === null)
                            <x-core::status-badge tone="error" label="Perfil inválido" />
                        @else
                            {{ $profiles[$campus->id] }}
                        @endif
                    </div>

                    <div class="-ms-3 flex gap-1">
                        <x-button
                            label="Editar"
                            icon="o-pencil-square"
                            wire:click="edit('{{ $campus->id }}')"
                            aria-label="Editar {{ $campus->code }}"
                            class="btn-ghost btn-sm"
                            spinner="edit('{{ $campus->id }}')"
                        />

                        @can('update', $campus)
                            <x-button
                                :label="$campus->is_active ? 'Desactivar' : 'Activar'"
                                wire:click="toggleActive('{{ $campus->id }}')"
                                wire:confirm="{{ $campus->is_active ? '¿Desactivar el campus '.$campus->code.'? Nadie podrá trabajar en él hasta que se reactive.' : '¿Activar el campus '.$campus->code.'?' }}"
                                class="btn-ghost btn-sm"
                                spinner="toggleActive('{{ $campus->id }}')"
                            />
                        @else
                            <span class="tooltip tooltip-right" data-tip="Solo el administrador activa o desactiva campus.">
                                <x-button :label="$campus->is_active ? 'Desactivar' : 'Activar'" class="btn-ghost btn-sm" disabled />
                            </span>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($campuses->isNotEmpty())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                <p>Campus <span class="font-mono">{{ $campuses->first()->code }}</span>@if ($campuses->count() > 1) a <span class="font-mono">{{ $campuses->last()->code }}</span> · <span class="font-mono">{{ $campuses->firstItem() }}–{{ $campuses->lastItem() }}</span>@else · <span class="font-mono">{{ $campuses->firstItem() }}</span>@endif de <span class="font-mono">{{ $campuses->total() }}</span></p>

                {{ $campuses->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-modal wire:model="showModal" :title="$editingId === null ? 'Nuevo campus' : 'Editar campus'" box-class="max-w-3xl" separator>
        <x-form wire:submit="save" no-separator>
            @if ($profileWasReset)
                <x-alert
                    title="El perfil de flujo guardado no es válido; se cargaron los valores por omisión. Revísalos y guarda para repararlo."
                    icon="o-exclamation-triangle"
                    class="alert-warning"
                />
            @endif

            <div class="grid gap-4 md:grid-cols-3">
                <x-input label="Código" name="code" wire:model="code" placeholder="C3" :disabled="! $canEditData" />
                <x-input label="Nombre" name="name" wire:model="name" :disabled="! $canEditData" />
                <x-select label="Modalidad" name="modality" wire:model="modality" :options="$modalities" :disabled="! $canEditData" />
            </div>

            @unless ($canEditData)
                <p class="text-sm text-base-content/70">Solo el administrador cambia código, nombre y modalidad.</p>
            @endunless

            <x-header title="Perfil de flujo" subtitle="Qué pasos del proceso aplican en este campus." size="text-lg" class="!mb-0 mt-4" />

            <div class="grid gap-x-4 md:grid-cols-2">
                <x-toggle label="Usa salones" wire:model="settings.uses_rooms" />
                <x-toggle label="Usa bloques de horario" wire:model="settings.uses_time_blocks" />
                <x-toggle label="Admite docentes externos" wire:model="settings.allow_external_teachers" />
                <x-toggle label="Requiere visto bueno de Subdirección" hint="Antes de publicar la carga." wire:model="settings.requires_subdireccion_approval" />
            </div>

            <div class="grid gap-4 md:grid-cols-4">
                <x-select label="Asignación docente" wire:model="settings.staffing_mode" :options="$staffingModes" />
                <x-input label="Duración del bloque" type="number" min="1" suffix="min" wire:model="settings.block_minutes" />
                <x-input label="Inicio de la jornada" type="time" wire:model="settings.day_start" />
                <x-input label="Fin de la jornada" type="time" wire:model="settings.day_end" />
            </div>

            <fieldset>
                <legend class="text-sm font-medium">Días</legend>

                <div class="flex flex-wrap gap-x-4">
                    @foreach ($days as $value => $label)
                        <x-checkbox :label="$label" value="{{ $value }}" wire:model="settings.days" omit-error />
                    @endforeach
                </div>

                @error('settings.days')
                    <div class="text-sm text-error">{{ $message }}</div>
                @enderror
            </fieldset>

            @error('settings')
                <x-alert :title="$message" icon="o-x-circle" class="alert-error" />
            @enderror

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
