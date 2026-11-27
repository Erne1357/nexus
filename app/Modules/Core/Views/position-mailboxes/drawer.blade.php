{{--
    Cajón «Agregar buzón» / «Editar buzón» (CE-02; ADR-0023): el puesto, solo el alcance que ese puesto admite (vacío es
    «todas»: modalidad, campus, carrera o unidad, con las opciones de «Nombrar») y el correo institucional del buzón.
    Guardar lo deja activo. Los errores de SavePositionMailbox salen junto a su campo. El componente es
    core::pages.position-mailboxes; espera $roles, $roleCase, $columns, $options y $domains.
--}}
<form wire:submit="save" class="flex h-dvh flex-col">
    <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
        <div>
            <h2 id="mailbox-drawer-title" class="text-xl font-bold">{{ $editingId === null ? 'Agregar buzón' : 'Editar buzón' }}</h2>
            <p class="text-sm text-neutral">A este correo llegan los avisos del puesto, no los de cada persona.</p>
        </div>
        <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto max-md:size-11" @click="$wire.showDrawer = false" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
    </header>

    <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
        <x-select label="Puesto" name="role" wire:model.live="role" :options="$roles" placeholder="Elige un puesto" data-testid="mailbox-role" />

        @if ($columns !== [])
            <fieldset class="space-y-4" data-testid="mailbox-scope">
                <legend class="text-sm font-semibold">Alcance <span class="font-normal text-neutral">(lo que dejes vacío vale para todas)</span></legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    @if (in_array('modality_id', $columns, true))
                        <x-select label="Modalidad" name="modality" wire:model.live="modality" :options="$options['modalities']" placeholder="Todas las modalidades" />
                    @endif

                    @if (in_array('campus_id', $columns, true) && $options['campuses'] !== [])
                        <x-select label="Campus" name="campus" wire:model.live="campus" :options="$options['campuses']" placeholder="Todos los campus" />
                    @endif

                    @if (in_array('career_id', $columns, true))
                        <div class="sm:col-span-2">
                            <x-select label="Carrera" name="career" wire:model="career" :options="$options['careers']" placeholder="Todas las carreras" />
                        </div>
                    @endif

                    @if (in_array('org_unit_id', $columns, true))
                        <div class="sm:col-span-2">
                            <x-select :label="$roleCase?->unitLabel() ?? 'Unidad'" name="unit" wire:model="unit" :options="$options['units']" placeholder="Todas las unidades" />
                        </div>
                    @endif
                </div>
            </fieldset>
        @endif

        <x-input
            label="Correo del buzón"
            name="email"
            type="email"
            wire:model="email"
            :placeholder="'jefatura@'.($domains[0] ?? 'cdjuarez.tecnm.mx')"
            :hint="'Solo un correo institucional (@'.implode(' o @', $domains).'). Al guardar salen los correos que esperaban este buzón.'"
            class="font-mono"
            autocomplete="off"
            data-testid="mailbox-email"
        />
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 px-5 py-3">
        <p class="text-sm text-neutral">Cada cambio queda en la bitácora.</p>
        <div class="flex gap-2">
            <x-button label="Cancelar" @click="$wire.showDrawer = false" class="max-md:h-11" />
            <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary max-md:h-11" spinner="save" data-testid="mailbox-save" />
        </div>
    </footer>
</form>
