{{--
    Acciones de un buzón de puesto, las mismas en la tabla y en los renglones apilados del celular
    (core::pages.position-mailboxes; DESIGN.md, «Acciones de fila»): «Editar» visible y «Desactivar» en «Acciones», con la
    confirmación del diálogo de Core. Un buzón inactivo se reactiva editándolo y guardando. Lo que no se puede queda
    deshabilitado con su motivo. En la tabla (`$inTable`) los botones llevan su data-testid y quedan solo con su icono
    entre 768 y 1199 px.
--}}
@php
    $name = $mailbox->email;
@endphp

<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    @can('update', $mailbox)
        <x-button icon="o-pencil-square" wire:click="edit('{{ $mailbox->id }}')" aria-label="Editar el buzón {{ $name }}" :data-testid="$inTable ? 'edit-mailbox' : null" class="btn-ghost btn-sm" spinner="edit('{{ $mailbox->id }}')">
            <span @class(['max-[1200px]:hidden' => $inTable])>{{ $mailbox->is_active ? 'Editar' : 'Editar y activar' }}</span>
        </x-button>
    @else
        <span class="tooltip tooltip-left" data-tip="Solo el administrador cambia buzones de puesto.">
            <x-button icon="o-pencil-square" aria-label="Editar el buzón {{ $name }}" class="btn-ghost btn-sm" disabled>
                <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
            </x-button>
        </span>
    @endcan

    <x-core::row-menu :name="$name" :compact="$inTable">
        @can('deactivate', $mailbox)
            <x-core::row-menu-item
                label="Desactivar"
                wire:click="deactivate('{{ $mailbox->id }}')"
                x-confirm="Los correos nuevos de este puesto esperarán en Nexus hasta que tenga otro buzón activo. Lo que ya salió no cambia."
                data-confirm-title="¿Desactivar el buzón {{ $name }}?"
                data-confirm-button="Desactivar"
                data-confirm-tone="error"
                :spinner="'deactivate(\''.$mailbox->id.'\')'"
                :data-testid="$inTable ? 'deactivate-mailbox' : null"
            />
        @else
            <x-core::row-menu-item label="Desactivar" :reason="$mailbox->is_active ? 'Solo el administrador desactiva buzones de puesto.' : 'Ya está inactivo: edítalo y guarda para activarlo.'" disabled />
        @endcan
    </x-core::row-menu>
</div>
