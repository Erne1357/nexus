<div>
    <x-header title="Campus" subtitle="Cada campus con su modalidad y su perfil de flujo, que decide qué pasos del proceso aplican." separator progress-indicator>
        <x-slot:actions>
            <x-input
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar por código o nombre"
                aria-label="Buscar campus"
                icon="o-magnifying-glass"
                clearable
            />

            @if ($canSeeInactive)
                <x-select
                    wire:model.live="status"
                    aria-label="Estado"
                    :options="$statuses"
                    placeholder="Activos e inactivos"
                />
            @else
                <span class="tooltip tooltip-bottom" data-tip="Solo el administrador ve los campus inactivos.">
                    <x-select aria-label="Estado" :options="[]" placeholder="Activos" disabled />
                </span>
            @endif

            @if ($canCreate)
                <x-button label="Nuevo campus" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" />
            @else
                <span class="tooltip tooltip-left" data-tip="Solo el administrador da de alta campus.">
                    <x-button label="Nuevo campus" icon="o-plus" class="btn-primary" disabled />
                </span>
            @endif
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <x-table :headers="$headers" :rows="$campuses" with-pagination>
            @scope('cell_modality', $campus)
                {{ $campus->modality->label() }}
            @endscope

            @scope('cell_is_active', $campus)
                @if ($campus->is_active)
                    <x-badge value="Activo" class="badge-success badge-soft" />
                @else
                    <x-badge value="Inactivo" class="badge-ghost" />
                @endif
            @endscope

            @scope('actions', $campus)
                <div class="flex justify-end gap-1">
                    <x-button
                        icon="o-pencil-square"
                        wire:click="edit({{ $campus->id }})"
                        aria-label="Editar {{ $campus->code }}"
                        data-testid="edit-{{ $campus->code }}"
                        class="btn-ghost btn-sm"
                        spinner="edit({{ $campus->id }})"
                    />

                    @can('update', $campus)
                        <x-button
                            :label="$campus->is_active ? 'Desactivar' : 'Activar'"
                            wire:click="toggleActive({{ $campus->id }})"
                            wire:confirm="{{ $campus->is_active ? '¿Desactivar el campus '.$campus->code.'? Nadie podrá trabajar en él hasta que se reactive.' : '¿Activar el campus '.$campus->code.'?' }}"
                            class="btn-ghost btn-sm"
                            spinner="toggleActive({{ $campus->id }})"
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
    </x-card>

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
