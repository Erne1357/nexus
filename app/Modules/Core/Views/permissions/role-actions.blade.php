{{--
    Acciones de un rol, las mismas en la tabla y en los renglones apilados (core::permissions.roles-tab): «Editar»
    visible y en «Acciones» Restablecer, Aplicar lo nuevo de fábrica, Activar o Desactivar y Borrar (DESIGN.md,
    "Acciones de fila"). Lo que no se puede queda deshabilitado con su motivo escrito: solo los de fábrica se
    restablecen, y no se desactivan ni se borran; un rol que algún puesto tiene no se borra (CF-02, CF-S2). En la tabla
    (`$inTable`), entre 768 y 1199 px quedan solo con su icono. Espera `$role` y `$inTable`.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="editRole('{{ $role['id'] }}')"
        aria-label="Editar {{ $role['name'] }}"
        class="btn-ghost btn-sm max-md:h-11"
        spinner="editRole('{{ $role['id'] }}')"
        :disabled="! $canManage"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-core::row-menu :name="$role['name']" :compact="$inTable">
        @if ($role['restoreReason'] === null)
            <x-core::row-menu-item
                label="Restablecer"
                wire:click="restoreRole('{{ $role['id'] }}')"
                x-confirm="Vuelve a seguir al código y se pierde su lista propia."
                data-confirm-title="¿Restablecer «{{ $role['name'] }}»?"
                data-confirm-button="Restablecer"
                data-confirm-tone="warning"
                :spinner="'restoreRole(\''.$role['id'].'\')'"
            />
        @else
            <x-core::row-menu-item label="Restablecer" :reason="$role['restoreReason']" disabled />
        @endif

        @if ($role['newCount'] > 0)
            <x-core::row-menu-item
                :label="'Aplicar '.$role['newCount'].($role['newCount'] === 1 ? ' permiso nuevo de fábrica' : ' permisos nuevos de fábrica')"
                wire:click="applyFactoryUpdates('{{ $role['id'] }}')"
                :spinner="'applyFactoryUpdates(\''.$role['id'].'\')'"
            />
        @endif

        @if ($role['toggleReason'] !== null)
            <x-core::row-menu-item :label="$role['is_active'] ? 'Desactivar' : 'Activar'" :reason="$role['toggleReason']" disabled />
        @elseif ($role['is_active'])
            <x-core::row-menu-item
                label="Desactivar"
                wire:click="setRoleActive('{{ $role['id'] }}', false)"
                x-confirm="{{ $role['assigned'] > 0 ? 'Deja de aportar permisos a '.$role['assigned'].($role['assigned'] === 1 ? ' puesto' : ' puestos').', pero sigue asignado.' : 'Deja de aportar permisos.' }}"
                data-confirm-title="¿Desactivar «{{ $role['name'] }}»?"
                data-confirm-button="Desactivar"
                data-confirm-tone="error"
                :spinner="'setRoleActive(\''.$role['id'].'\', false)'"
            />
        @else
            <x-core::row-menu-item label="Activar" wire:click="setRoleActive('{{ $role['id'] }}', true)" :spinner="'setRoleActive(\''.$role['id'].'\', true)'" />
        @endif

        @if ($role['deleteReason'] !== null)
            <x-core::row-menu-item label="Borrar" :reason="$role['deleteReason']" disabled />
        @else
            <x-core::row-menu-item
                label="Borrar"
                danger
                wire:click="deleteRole('{{ $role['id'] }}')"
                x-confirm="Deja de aparecer en la lista. La bitácora conserva su historia."
                data-confirm-title="¿Borrar «{{ $role['name'] }}»?"
                data-confirm-button="Borrar"
                data-confirm-tone="error"
                :spinner="'deleteRole(\''.$role['id'].'\')'"
            />
        @endif
    </x-core::row-menu>
</div>
