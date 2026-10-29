{{--
    Acciones de una materia, las mismas en la tabla y en los renglones apilados del celular
    (academic::pages.subjects). En la tabla (`$inTable`), «Editar» queda solo con su icono entre 768 y
    1199 px (DESIGN.md, "Responsivo") y lleva el data-testid del browser test; en el celular siempre
    lleva su texto y no repite el data-testid. No hay «Borrar»: una materia no se borra, se desactiva,
    y si todavía la necesitan alumnos, desactivarla se rechaza con un toast que dice cuántos.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $subject->id }}')"
        aria-label="Editar {{ $subject->code }}"
        :data-testid="$inTable ? 'edit-'.$subject->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $subject->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-button
        :label="$subject->is_active ? 'Desactivar' : 'Activar'"
        wire:click="toggleActive('{{ $subject->id }}')"
        wire:confirm="{{ $subject->is_active ? '¿Desactivar la materia '.$subject->code.'? No se podrá si hay alumnos que todavía la necesitan.' : '¿Activar la materia '.$subject->code.'?' }}"
        class="btn-ghost btn-sm"
        spinner="toggleActive('{{ $subject->id }}')"
    />
</div>
