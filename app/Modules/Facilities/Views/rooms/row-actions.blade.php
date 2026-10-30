{{--
    Acciones de un salón, las mismas en la tabla y en los renglones apilados del celular
    (facilities::pages.rooms): «Editar» visible y lo demás plegado en «Acciones» (DESIGN.md, "Acciones de
    fila"): «Bloqueos…» abre el panel lateral del salón y «Desactivar» o «Activar» cambia su estado. En la tabla
    (`$inTable`), los botones quedan solo con su icono entre 768 y 1199 px y «Editar» lleva el data-testid del
    browser test; en el celular siempre llevan su texto y no repiten el data-testid. No hay «Borrar»: un
    salón no se borra, se desactiva.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $room->id }}')"
        aria-label="Editar {{ $room->code }}"
        :data-testid="$inTable ? 'edit-'.$room->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $room->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-core::row-menu :name="$room->code" :compact="$inTable">
        <x-core::row-menu-item
            label="Bloqueos…"
            :reason="trans_choice('{0} Sin bloqueos|{1} 1 bloqueo|[2,*] :count bloqueos', $room->blocks_count)"
            wire:click="openBlocks('{{ $room->id }}')"
            :data-testid="$inTable ? 'blocks-'.$room->code : null"
            :spinner="'openBlocks(\''.$room->id.'\')'"
        />

        <x-core::row-menu-item
            :label="$room->is_active ? 'Desactivar' : 'Activar'"
            wire:click="toggleActive('{{ $room->id }}')"
            wire:confirm="{{ $room->is_active ? '¿Desactivar el salón '.$room->code.'?' : '¿Activar el salón '.$room->code.'?' }}"
            :spinner="'toggleActive(\''.$room->id.'\')'"
        />
    </x-core::row-menu>
</div>
