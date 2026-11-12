{{--
    El panel de una modalidad (core::pages.modalities), dentro del x-drawer (U4 opción A): encabezado con el título y su
    línea de apoyo; «Así se resume» con el perfil que se edita; «Datos» (clave, nombre y «Activa»), «Salones y horario»
    (salones, bloques, duración, jornada y días en casillas de letra) y «Asignación y aprobación». Los campos se llaman
    como las claves de la Action (`code`, `settings.day_start`), así sus errores caen en su campo. Nada se guarda hasta
    «Guardar». La subdirección ve «Datos» deshabilitado con su motivo. `$summary` es el perfil en una línea (null si lo
    escrito no es un perfil válido todavía); `close()` es del x-drawer.
--}}
<form wire:submit="save" class="flex h-dvh flex-col" data-testid="modality-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="modality-drawer-title" class="text-xl font-bold leading-tight">{{ $editingId === null ? 'Nueva modalidad' : 'Editar '.$editingName }}</h2>
            <p class="mt-0.5 text-sm text-neutral">El perfil de flujo decide qué pasos aplican a la carga de la modalidad.</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:size-11" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-6 overflow-y-auto px-5 py-4">
        @if ($profileWasReset)
            <x-alert
                title="El perfil de flujo guardado no es válido; se cargaron los valores por omisión. Revísalos y guarda para repararlo."
                icon="o-exclamation-triangle"
                class="alert-warning"
            />
        @endif

        <p class="text-sm text-neutral" data-testid="profile-summary">
            Así se resume:
            @if ($summary !== null)
                <b class="font-semibold text-base-content">{{ $summary }}</b>
            @else
                <span>revisa los campos marcados.</span>
            @endif
        </p>

        <section class="space-y-3" aria-labelledby="mod-data">
            <h3 id="mod-data" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Datos</h3>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-input label="Clave" name="code" wire:model="code" placeholder="EAD" :disabled="! $canEditData" class="font-mono" />
                <x-input label="Nombre" name="name" wire:model="name" :disabled="! $canEditData" />
            </div>

            @if ($editingId !== null)
                <x-toggle
                    label="Activa"
                    hint="Solo una modalidad activa se abre en un periodo; los nombramientos de una inactiva no cuentan."
                    wire:model="isActive"
                    :disabled="! $canEditData"
                    right
                />
            @endif

            @unless ($canEditData)
                <p class="text-sm text-neutral">Solo el administrador cambia clave, nombre y estado.</p>
            @endunless
        </section>

        <section class="space-y-3" aria-labelledby="mod-rooms">
            <h3 id="mod-rooms" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Salones y horario</h3>

            <x-toggle label="Usa salones" hint="Cada grupo se da en un campus y lleva salón de ese campus." wire:model.live="settings.uses_rooms" right />
            <x-toggle label="Usa bloques de horario" hint="Las sesiones caben en bloques fijos de la jornada." wire:model.live="settings.uses_time_blocks" right />

            <div class="grid gap-3 sm:grid-cols-3">
                <x-input label="Duración del bloque" type="number" min="1" suffix="min" wire:model.live.debounce.400ms="settings.block_minutes" />
                <x-input label="Inicio de la jornada" type="time" wire:model.live="settings.day_start" />
                <x-input label="Fin de la jornada" type="time" wire:model.live="settings.day_end" data-testid="day-end" />
            </div>

            <fieldset>
                <legend class="mb-1 text-sm font-medium">Días</legend>

                <div class="flex flex-wrap gap-2" data-testid="days">
                    @foreach ($days as $value => $label)
                        <label class="cursor-pointer" title="{{ $label }}">
                            <input type="checkbox" class="peer sr-only" value="{{ $value }}" wire:model.live="settings.days" aria-label="{{ $label }}" />
                            <span class="btn btn-sm min-w-11 px-3 font-semibold peer-checked:btn-primary peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary max-md:h-11">{{ $dayLetters[$value] }}</span>
                        </label>
                    @endforeach
                </div>

                @error('settings.days')
                    <div class="mt-1 text-sm text-error">{{ $message }}</div>
                @enderror
            </fieldset>
        </section>

        <section class="space-y-3" aria-labelledby="mod-staffing">
            <h3 id="mod-staffing" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Asignación y aprobación</h3>

            <x-select label="Asignación docente" wire:model.live="settings.staffing_mode" :options="$staffingModes" />
            <x-toggle label="Admite docentes externos" wire:model.live="settings.allow_external_teachers" right />
            <x-toggle label="Requiere visto bueno de Subdirección" hint="Antes de publicar la carga." wire:model.live="settings.requires_subdireccion_approval" right />
        </section>

        @error('settings')
            <x-alert :title="$message" icon="o-x-circle" class="alert-error" />
        @enderror
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-base-300 bg-base-100 py-3 ps-5 pe-4">
        <p class="text-sm text-neutral">Cada cambio queda en la bitácora.</p>
        <div class="flex gap-2 max-md:w-full">
            <x-button label="Cancelar" class="max-md:h-11 max-md:flex-1" @click="close()" />
            <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary max-md:h-11 max-md:flex-1" spinner="save" data-testid="save-modality" />
        </div>
    </footer>
</form>
