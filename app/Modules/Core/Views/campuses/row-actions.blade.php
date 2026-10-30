{{--
    Acciones de un campus, las mismas en la tabla y en los renglones apilados del celular
    (core::pages.campuses): «Editar» visible y lo demás plegado en «Acciones» (DESIGN.md, "Acciones de
    fila"). En la tabla (`$inTable`), «Editar» y «Acciones» quedan solo con su icono entre 768 y 1199 px
    y «Editar» lleva el data-testid del browser test; en el celular siempre llevan su texto y no repiten
    el data-testid. Lo que el rol no permite se muestra deshabilitado, en el menú, con su motivo escrito.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $campus->id }}')"
        aria-label="Editar {{ $campus->code }}"
        :data-testid="$inTable ? 'edit-'.$campus->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $campus->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-core::row-menu :name="$campus->code" :compact="$inTable">
        @can('update', $campus)
            <x-core::row-menu-item
                :label="$campus->is_active ? 'Desactivar' : 'Activar'"
                wire:click="toggleActive('{{ $campus->id }}')"
                wire:confirm="{{ $campus->is_active ? '¿Desactivar el campus '.$campus->code.'? Nadie podrá trabajar en él hasta que se reactive.' : '¿Activar el campus '.$campus->code.'?' }}"
                :spinner="'toggleActive(\''.$campus->id.'\')'"
            />
        @else
            <x-core::row-menu-item
                :label="$campus->is_active ? 'Desactivar' : 'Activar'"
                reason="Solo el administrador activa o desactiva campus."
                disabled
            />
        @endcan
    </x-core::row-menu>
</div>
