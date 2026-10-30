{{--
    Acciones de una carrera, las mismas en la tabla y en los renglones apilados del celular
    (academic::pages.careers): «Editar» visible y lo demás plegado en «Acciones» (DESIGN.md, "Acciones
    de fila"). En la tabla (`$inTable`), los botones quedan solo con su icono entre 768 y 1199 px y
    «Editar» lleva el data-testid del browser test; en el celular siempre llevan su texto y no repiten
    el data-testid. «Ofrecer» y «Dejar de ofrecer» actúan sobre el campus activo (sin confirmación: es
    reversible); sin uno, el elemento se deshabilita y pide el campus. «Borrar» se deshabilita, con su
    motivo escrito, si la carrera tiene planes.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $career->id }}')"
        aria-label="Editar {{ $career->code }}"
        :data-testid="$inTable ? 'edit-'.$career->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $career->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-core::row-menu :name="$career->code" :compact="$inTable">
        @if ($campus === null)
            <x-core::row-menu-item label="Ofrecer en el campus" reason="Elige un campus en tu credencial." disabled />
        @else
            <x-core::row-menu-item
                :label="($career->offered_here ? 'Dejar de ofrecer en ' : 'Ofrecer en ').$campus->code"
                wire:click="toggleOffering('{{ $career->id }}')"
                :data-testid="$inTable ? 'offering-'.$career->code : null"
                :spinner="'toggleOffering(\''.$career->id.'\')'"
            />
        @endif

        <x-core::row-menu-item
            :label="$career->is_active ? 'Desactivar' : 'Activar'"
            wire:click="toggleActive('{{ $career->id }}')"
            wire:confirm="{{ $career->is_active ? '¿Desactivar la carrera '.$career->code.'?' : '¿Activar la carrera '.$career->code.'?' }}"
            :spinner="'toggleActive(\''.$career->id.'\')'"
        />

        @if ($career->study_plans_count > 0)
            <x-core::row-menu-item label="Borrar" reason="Solo se borra una carrera sin planes de estudio." disabled />
        @else
            <x-core::row-menu-item
                label="Borrar"
                wire:click="delete('{{ $career->id }}')"
                wire:confirm="¿Borrar la carrera {{ $career->code }}? Dejará de aparecer en el catálogo y se cerrarán los alcances de sus coordinadores."
                :spinner="'delete(\''.$career->id.'\')'"
                danger
            />
        @endif
    </x-core::row-menu>
</div>
