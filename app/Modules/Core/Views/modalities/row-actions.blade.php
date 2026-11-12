{{--
    Acciones de una modalidad, las mismas en la tabla y en los renglones apilados del celular (core::pages.modalities),
    como las del campus (DESIGN.md, "Acciones de fila"): «Editar» visible y «Activar» o «Desactivar» plegado en
    «Acciones». Lo que el rol no permite se muestra deshabilitado, en el menú, con su motivo escrito.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $modality->id }}')"
        aria-label="Editar {{ $modality->code }}"
        :data-testid="$inTable ? 'edit-'.$modality->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $modality->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-core::row-menu :name="$modality->code" :compact="$inTable">
        @can('update', $modality)
            <x-core::row-menu-item
                :label="$modality->is_active ? 'Desactivar' : 'Activar'"
                wire:click="toggleActive('{{ $modality->id }}')"
                x-confirm="{{ $modality->is_active ? 'Nadie podrá abrir periodos ni trabajar en la modalidad '.$modality->name.' hasta que se reactive.' : 'La modalidad '.$modality->name.' vuelve a estar disponible para abrir periodos y trabajar.' }}"
                data-confirm-title="{{ $modality->is_active ? '¿Desactivar la modalidad '.$modality->name.'?' : '¿Activar la modalidad '.$modality->name.'?' }}"
                data-confirm-button="{{ $modality->is_active ? 'Desactivar' : 'Activar' }}"
                data-confirm-tone="{{ $modality->is_active ? 'error' : 'primary' }}"
                :spinner="'toggleActive(\''.$modality->id.'\')'"
            />
        @else
            <x-core::row-menu-item
                :label="$modality->is_active ? 'Desactivar' : 'Activar'"
                reason="Solo el administrador activa o desactiva modalidades."
                disabled
            />
        @endcan
    </x-core::row-menu>
</div>
