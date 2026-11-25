{{--
    Acciones de una materia, las mismas en la tabla y en los renglones apilados del celular
    (academic::pages.subjects): «Editar» visible y lo demás plegado en «Acciones» (DESIGN.md, "Acciones
    de fila"). En la tabla (`$inTable`), los botones quedan solo con su icono entre 768 y 1199 px y
    «Editar» lleva el data-testid del browser test; en el celular siempre llevan su texto y no repiten
    el data-testid. No hay «Borrar»: una materia no se borra, se desactiva, y si todavía la necesitan
    alumnos, desactivarla se rechaza con un toast que dice cuántos. «Tipo de espacio» (SEL-17) lo ve solo quien puede
    cambiarlo en esa materia (el jefe de su departamento y el admin): quien ve el catálogo lo tiene en «Acciones»; quien solo
    puede eso (sin `viewCatalog`) lo tiene como único botón visible y no ve «Editar» ni «Acciones».
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    @cannot('viewCatalog', \App\Modules\Academic\Models\Subject::class)
        @can('setSpace', $subject)
            <x-button
                icon="o-map-pin"
                wire:click="editSpace('{{ $subject->id }}')"
                aria-label="Tipo de espacio de {{ $subject->code }}"
                :data-testid="$inTable ? 'space-'.$subject->code : null"
                class="btn-ghost btn-sm"
                spinner="editSpace('{{ $subject->id }}')"
            >
                <span @class(['max-[1200px]:hidden' => $inTable])>Tipo de espacio</span>
            </x-button>
        @endcan
    @endcannot

    @can('viewCatalog', \App\Modules\Academic\Models\Subject::class)
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

    <x-core::row-menu :name="$subject->code" :compact="$inTable">
        @can('setSpace', $subject)
            <x-core::row-menu-item
                label="Tipo de espacio"
                wire:click="editSpace('{{ $subject->id }}')"
                :spinner="'editSpace(\''.$subject->id.'\')'"
            />
        @endcan
        <x-core::row-menu-item
            :label="$subject->is_active ? 'Desactivar' : 'Activar'"
            wire:click="toggleActive('{{ $subject->id }}')"
            x-confirm="{{ $subject->is_active ? 'No se podrá si hay alumnos que todavía la necesitan.' : 'La materia vuelve a estar disponible.' }}"
            data-confirm-title="{{ $subject->is_active ? '¿Desactivar la materia '.$subject->code.'?' : '¿Activar la materia '.$subject->code.'?' }}"
            data-confirm-button="{{ $subject->is_active ? 'Desactivar' : 'Activar' }}"
            data-confirm-tone="{{ $subject->is_active ? 'error' : 'primary' }}"
            :spinner="'toggleActive(\''.$subject->id.'\')'"
        />
    </x-core::row-menu>
    @endcan
</div>
