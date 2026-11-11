{{--
    Acciones de un periodo, las mismas en la tabla y en los renglones apilados del celular
    (core::pages.terms): «Editar» visible y lo demás plegado en «Acciones» (DESIGN.md, "Acciones de
    fila"). En la tabla (`$inTable`), «Editar» y «Acciones» quedan solo con su icono entre 768 y 1299 px
    y «Editar» lleva el data-testid del browser test. Lo que no aplica se muestra deshabilitado, en el
    menú, con su motivo escrito. «Ver ficha» abre core::pages.term.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3 max-md:[&>button]:min-h-11 max-md:[&>div>button]:min-h-11' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $term->id }}')"
        aria-label="Editar {{ $term->code }}"
        :data-testid="$inTable ? 'edit-'.$term->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $term->id }}')"
    >
        <span @class(['max-[1299px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-core::row-menu :name="$term->code" :compact="$inTable">
        <x-core::row-menu-item label="Ver ficha" :href="route('core.terms.show', $term->id)" :data-testid="$inTable ? 'show-'.$term->code : null" />

        @if ($term->is_current)
            <x-core::row-menu-item label="Marcar como actual" reason="Ya es el periodo actual." disabled />
        @else
            @can('makeCurrent', $term)
                <x-core::row-menu-item
                    label="Marcar como actual"
                    wire:click="makeCurrent('{{ $term->id }}')"
                    x-confirm="Será el periodo de omisión de la credencial."
                    data-confirm-title="¿Marcar el periodo {{ $term->code }} como el actual?"
                    data-confirm-button="Marcar como actual"
                    data-confirm-tone="warning"
                    :data-testid="$inTable ? 'make-current-'.$term->code : null"
                    :spinner="'makeCurrent(\''.$term->id.'\')'"
                />
            @else
                <x-core::row-menu-item label="Marcar como actual" reason="Solo el administrador o la subdirección marcan el periodo actual." disabled />
            @endcan
        @endif
    </x-core::row-menu>
</div>
