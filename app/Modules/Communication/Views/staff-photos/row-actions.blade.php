{{--
    Acciones de una persona en «Fotos del personal» (communication::pages.staff-photos), las mismas en la tabla y en los
    renglones del celular: «Subir foto» o «Cambiar foto» visible y lo demás en el menú «Acciones». `$person` es la persona;
    `$photo` su URL firmada o null.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button icon="o-arrow-up-tray" wire:click="openPhoto('{{ $person->id }}')" aria-label="{{ $photo === null ? 'Subir foto' : 'Cambiar foto' }} de {{ $person->name }}" class="btn-ghost btn-sm" spinner="openPhoto('{{ $person->id }}')" data-testid="photo-open-{{ $person->username }}">
        <span @class(['max-[1200px]:hidden' => $inTable])>{{ $photo === null ? 'Subir foto' : 'Cambiar foto' }}</span>
    </x-button>

    <x-core::row-menu :name="$person->name" :compact="$inTable">
        @if ($photo !== null)
            <x-core::row-menu-item label="Ver foto" :href="$photo" target="_blank" rel="noopener" />
            <x-core::row-menu-item label="Quitar foto" danger wire:click="openRemove('{{ $person->id }}')" :spinner="'openRemove(\''.$person->id.'\')'" data-testid="photo-remove-{{ $person->username }}" />
        @else
            <x-core::row-menu-item label="Quitar foto" reason="Todavía no tiene foto." disabled />
        @endif
    </x-core::row-menu>
</div>
