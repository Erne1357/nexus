{{--
    «Editar datos» de la cuenta (1.22-C1, CF-S3) en un `x-modal` (ADR-0023): el usuario se ve pero no se edita; el
    nombre y el correo institucional sí. Los errores de UpdateUserProfile salen junto a su campo. Espera $person.
--}}
<x-modal wire:model="showProfile" title="Editar datos" :subtitle="$person->name" box-class="max-w-xl" separator data-testid="profile-modal">
    <x-form wire:submit="saveProfile" no-separator>
        <p class="text-sm"><span class="font-semibold">Usuario</span> <span class="ms-2 font-mono tabular-nums">{{ $person->username }}</span> <span class="text-neutral">· no se edita</span></p>
        <x-input label="Nombre" name="profileName" wire:model="profileName" autocomplete="off" data-testid="profile-name" />
        <x-input label="Correo institucional (opcional)" name="profileEmail" type="email" wire:model="profileEmail" hint="Cambiarlo deja sin efecto la invitación y el enlace pendientes." autocomplete="off" data-testid="profile-email" />

        <x-slot:actions>
            <x-button label="Cancelar" @click="$wire.showProfile = false" class="max-md:h-11" />
            <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary max-md:h-11" spinner="saveProfile" data-testid="profile-submit" />
        </x-slot:actions>
    </x-form>
</x-modal>
