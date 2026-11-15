{{--
    El menú «Acciones» de un trabajo fallido en «Correos y colas» (CF-S8), en la tabla y en los renglones apilados de
    celular: «Ver error» y, para el admin, «Reintentar» y «Descartar» (con su confirmación). Espera $job y $inTable.
--}}
<x-core::row-menu :name="$job->job_name" :compact="$inTable">
    <x-core::row-menu-item label="Ver error" wire:click="openError('{{ $job->uuid }}')" :spinner="'openError(\''.$job->uuid.'\')'" />
    @can('retry', $job)
        <x-core::row-menu-item label="Reintentar" wire:click="retry('{{ $job->uuid }}')" :spinner="'retry(\''.$job->uuid.'\')'" />
        <x-core::row-menu-item
            label="Descartar"
            danger
            wire:click="forget('{{ $job->uuid }}')"
            x-confirm="El trabajo se borra de la lista de fallidos y no se vuelve a correr."
            data-confirm-title="¿Descartar este trabajo?"
            data-confirm-button="Descartar"
            data-confirm-tone="error"
            :spinner="'forget(\''.$job->uuid.'\')'"
        />
    @else
        <x-core::row-menu-item label="Reintentar" reason="Solo el administrador reintenta trabajos." disabled />
        <x-core::row-menu-item label="Descartar" reason="Solo el administrador descarta trabajos." disabled />
    @endcan
</x-core::row-menu>
