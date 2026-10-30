{{--
    Acciones de un edificio, las mismas en la tabla y en los renglones apilados del celular
    (facilities::pages.buildings): «Editar» visible y «Desactivar» o «Activar» plegado en «Acciones»
    (DESIGN.md, "Acciones de fila"). En la tabla (`$inTable`), los botones quedan solo con su icono entre
    768 y 1199 px y «Editar» lleva el data-testid del browser test; en el celular siempre llevan su texto y
    no repiten el data-testid. No hay «Borrar»: un edificio no se borra, se desactiva.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $building->id }}')"
        aria-label="Editar {{ $building->code }}"
        :data-testid="$inTable ? 'edit-'.$building->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $building->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-core::row-menu :name="$building->code" :compact="$inTable">
        <x-core::row-menu-item
            :label="$building->is_active ? 'Desactivar' : 'Activar'"
            wire:click="toggleActive('{{ $building->id }}')"
            wire:confirm="{{ $building->is_active ? '¿Desactivar el edificio '.$building->code.'?' : '¿Activar el edificio '.$building->code.'?' }}"
            :spinner="'toggleActive(\''.$building->id.'\')'"
        />
    </x-core::row-menu>
</div>
