{{--
    «Agregar rol» (core::pages.permissions; ADR-0023), dentro del x-drawer: los roles como opciones, primero los que se
    le pueden dar al puesto, luego los que ya tiene («Ya lo tiene.») y al final los que traen un candado (CF-03) o algo
    que el puesto no acota (CF-04), deshabilitados con el motivo de las reglas. Uno desactivado se puede dar, pero no
    aporta hasta que se active (CF-S2). Abajo, «Nuevo rol» abre el editor. Confirmar llama a `assignRole`; los errores
    de la Action salen arriba. `close()` es del x-drawer. Espera `$addRole`.
--}}
<div class="flex h-dvh flex-col" data-testid="add-role-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="add-role-title" class="text-xl font-bold leading-tight">Agregar rol</h2>
            <p class="mt-0.5 text-sm text-neutral">{{ $addRole['label'] }} · {{ number_format($addRole['holders']) }} {{ $addRole['holders'] === 1 ? 'persona' : 'personas' }}</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:size-11" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-2 overflow-y-auto px-5 py-4">
        @foreach (['permission_set', 'role', 'addRoleId'] as $field)
            @error($field)
                <x-alert icon="o-x-circle" class="alert-error alert-soft" data-testid="add-role-error">{{ $message }}</x-alert>
            @enderror
        @endforeach

        <fieldset class="space-y-2">
            <legend class="sr-only">Rol</legend>
            @foreach ($addRole['options'] as $option)
                @php($free = $option['state'] === 'free')
                <label
                    wire:key="add-role-option-{{ $option['id'] }}"
                    data-testid="add-role-option-{{ $option['id'] }}"
                    @class([
                        'flex items-start gap-3 rounded-box border px-3.5 py-3',
                        'cursor-pointer border-base-300 bg-base-100 has-checked:border-primary has-checked:ring-1 has-checked:ring-primary' => $free,
                        'cursor-not-allowed border-base-300 bg-secondary/40' => ! $free,
                    ])
                >
                    <input type="radio" class="radio radio-sm mt-0.5" name="add-role" wire:model.live="addRoleId" value="{{ $option['id'] }}" @disabled(! $free) />
                    <span class="grid min-w-0 gap-0.5">
                        <span @class(['font-semibold', 'text-neutral' => ! $free])>{{ $option['name'] }}</span>
                        <span class="text-sm text-neutral">{{ $option['count'] }} {{ $option['count'] === 1 ? 'permiso' : 'permisos' }}@if ($option['positions'] !== []) · lo tienen {{ implode(', ', $option['positions']) }}@endif</span>
                        @if (! $option['is_active'])
                            <span class="text-[12.5px] text-neutral">Desactivado: no aporta hasta que se active.</span>
                        @endif
                        @if ($option['state'] === 'mine')
                            <span class="text-[12.5px] text-neutral">Ya lo tiene.</span>
                        @elseif ($option['state'] === 'blocked')
                            <span class="mt-0.5 flex items-start gap-1.5 text-[12.5px] text-neutral">
                                <x-icon name="o-no-symbol" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span>{{ $option['problem'] }}@if ($option['more'] > 0) Y {{ $option['more'] === 1 ? 'otro motivo' : $option['more'].' motivos más' }}.@endif</span>
                            </span>
                        @endif
                    </span>
                </label>
            @endforeach
        </fieldset>

        <x-button label="Nuevo rol" icon="o-plus" class="btn-ghost btn-sm max-md:h-11" wire:click="openNewRole" spinner="openNewRole" />
    </div>

    <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-base-300 px-5 py-3">
        <x-button label="Cancelar" class="btn-ghost max-md:h-11" @click="close()" />
        <x-button label="Agregar rol" class="btn-primary max-md:h-11" wire:click="assignRole" spinner="assignRole" :disabled="$addRoleId === ''" data-testid="add-role-submit" />
    </footer>
</div>
