{{--
    Acciones de una solicitud en «Solicitudes de credencial» (communication::pages.credential-requests), las mismas en la
    tabla y en los renglones del celular: la siguiente acción visible y el menú «Acciones». `$row` es la solicitud y
    `$next` el nombre de su siguiente acción.
--}}
<div @class(['flex gap-1', 'justify-end whitespace-nowrap' => $inTable, '-ms-3' => ! $inTable])>
    <x-button :label="$next" wire:click="openRequest('{{ $row->id }}')" aria-label="{{ $next }}: {{ $row->student->name }}" class="btn-ghost btn-sm" spinner="openRequest('{{ $row->id }}')" data-testid="request-open-{{ $row->student->control_number }}" />
    <x-core::row-menu :name="$row->student->name" :compact="$inTable">
        <x-core::row-menu-item label="Ver solicitud" wire:click="openRequest('{{ $row->id }}')" />
        @if ($row->status->isOpen())
            <x-core::row-menu-item label="Rechazar" :danger="true" wire:click="startReject('{{ $row->id }}')" />
        @endif
    </x-core::row-menu>
</div>
