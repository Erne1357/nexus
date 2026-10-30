{{--
    Acciones de un departamento, las mismas en la tabla y en los renglones apilados del celular
    (academic::pages.departments): «Editar» visible y lo demás plegado en «Acciones» (DESIGN.md,
    "Acciones de fila"). En la tabla (`$inTable`), los botones quedan solo con su icono entre 768 y
    1199 px y «Editar» lleva el data-testid del browser test; en el celular siempre llevan su texto y
    no repiten el data-testid. «Borrar» se deshabilita, con su motivo escrito, si el departamento
    tiene carreras o materias.
--}}
@php($hasDependents = $department->careers_count > 0 || $department->subjects_count > 0)

<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $department->id }}')"
        aria-label="Editar {{ $department->code }}"
        :data-testid="$inTable ? 'edit-'.$department->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $department->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-core::row-menu :name="$department->code" :compact="$inTable">
        <x-core::row-menu-item
            :label="$department->is_active ? 'Desactivar' : 'Activar'"
            wire:click="toggleActive('{{ $department->id }}')"
            wire:confirm="{{ $department->is_active ? '¿Desactivar el departamento '.$department->code.'?' : '¿Activar el departamento '.$department->code.'?' }}"
            :spinner="'toggleActive(\''.$department->id.'\')'"
        />

        @if ($hasDependents)
            <x-core::row-menu-item label="Borrar" reason="Solo se borra un departamento sin carreras ni materias." disabled />
        @else
            <x-core::row-menu-item
                label="Borrar"
                wire:click="delete('{{ $department->id }}')"
                wire:confirm="¿Borrar el departamento {{ $department->code }}? Dejará de aparecer en el catálogo y se cerrarán los alcances de sus jefes."
                :spinner="'delete(\''.$department->id.'\')'"
                danger
            />
        @endif
    </x-core::row-menu>
</div>
