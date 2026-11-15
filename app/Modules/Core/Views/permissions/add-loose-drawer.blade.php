{{--
    «Agregar permisos sueltos» (core::pages.permissions; ADR-0023), dentro del x-drawer: la lista de permisos por
    grupo (core::permissions.picker). Lo que ya tiene va marcado y deshabilitado con «Ya lo tiene: …»; los candados
    (CF-03) y lo que el puesto no acota (CF-04) van deshabilitados con su motivo. Un puesto sin alcance (docente,
    alumno) lo dice una vez arriba: solo recibe permisos personales. Confirmar llama a `grantLoose`, que los da todos o
    ninguno en una sola transacción (GrantPositionPermissions); sus errores salen arriba. `close()` es del x-drawer. Espera `$addLoose`.
--}}
<div class="flex h-dvh flex-col" data-testid="add-loose-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="add-loose-title" class="text-xl font-bold leading-tight">Agregar permisos sueltos</h2>
            <p class="mt-0.5 text-sm text-neutral">{{ $addLoose['label'] }} · {{ number_format($addLoose['holders']) }} {{ $addLoose['holders'] === 1 ? 'persona' : 'personas' }}</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:size-11" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-3 overflow-y-auto px-5 py-4">
        @if ($addLoose['noScope'])
            <div class="flex items-start gap-3 rounded-box border border-base-300 bg-secondary/40 px-4 py-3" data-testid="no-scope">
                <x-icon name="o-no-symbol" class="mt-0.5 h-5 w-5 shrink-0 text-neutral" aria-hidden="true" />
                <div>
                    <p class="font-semibold">{{ $addLoose['label'] }} no lleva alcance</p>
                    <p class="text-sm text-neutral">Solo recibe permisos personales.</p>
                </div>
            </div>
        @endif

        @foreach (['looseKeys', 'permissions', 'role'] as $field)
            @error($field)
                <x-alert icon="o-x-circle" class="alert-error alert-soft" data-testid="add-loose-error">
                    @foreach ($errors->get($field) as $line)
                        <span class="block">{{ $line }}</span>
                    @endforeach
                </x-alert>
            @enderror
        @endforeach

        @include('core::permissions.picker', ['groups' => $addLoose['groups'], 'target' => 'loose', 'model' => 'looseKeys'])
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 px-5 py-3">
        <p class="text-sm text-neutral">Se {{ $addLoose['remaining'] === 1 ? 'puede dar 1 más' : 'pueden dar '.number_format($addLoose['remaining']).' más' }}.</p>
        <div class="flex gap-2">
            <x-button label="Cancelar" class="btn-ghost max-md:h-11" @click="close()" />
            <x-button
                :label="$addLoose['selected'] > 0 ? 'Dar '.$addLoose['selected'].($addLoose['selected'] === 1 ? ' permiso' : ' permisos') : 'Dar permisos'"
                class="btn-primary max-md:h-11"
                wire:click="grantLoose"
                spinner="grantLoose"
                :disabled="$looseKeys === []"
                data-testid="add-loose-submit"
            />
        </div>
    </footer>
</div>
