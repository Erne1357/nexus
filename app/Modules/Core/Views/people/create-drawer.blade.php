{{--
    «Dar de alta» como cajón (1.22-C1, CF-S3; ADR-0023): usuario (RFC o número de empleado, no se edita después), nombre
    y correo institucional opcional. Nace sin acceso y la pantalla lleva a su ficha para nombrarlo. Los errores de las
    reglas y de CreateStaffUser salen junto a su campo. El componente es core::pages.people.
--}}
<form wire:submit="createUser" class="flex h-dvh flex-col">
    <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
        <div>
            <h2 id="create-user-title" class="text-xl font-bold">Dar de alta</h2>
            <p class="text-sm text-neutral">Nace sin acceso; después lo nombras en su ficha.</p>
        </div>
        <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto max-md:size-11" @click="$wire.showCreate = false" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
    </header>

    <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
        <x-input label="Usuario" name="newUsername" wire:model="newUsername" hint="RFC o número de empleado. No se edita después." class="font-mono" autocomplete="off" data-testid="new-username" />
        <x-input label="Nombre" name="newName" wire:model="newName" autocomplete="off" data-testid="new-name" />
        <x-input label="Correo institucional (opcional)" name="newEmail" type="email" wire:model="newEmail" :placeholder="'nombre@'.config('nexus.institutional_email_domain')" autocomplete="off" data-testid="new-email" />
    </div>

    <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-base-300 px-5 py-3">
        <x-button label="Cancelar" @click="$wire.showCreate = false" class="max-md:h-11" />
        <x-button label="Dar de alta" type="submit" icon="o-plus" class="btn-primary max-md:h-11" spinner="createUser" data-testid="create-user-submit" />
    </footer>
</form>
