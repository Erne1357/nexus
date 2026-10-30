{{--
    Acciones de un docente, las mismas en la tabla y en los renglones apilados del celular (faculty::pages.teachers):
    «Ficha» (el enlace a la ficha del docente) y «Editar» visibles y lo demás plegado en «Acciones» (DESIGN.md, "Acciones de fila"). En la tabla (`$inTable`), los
    botones quedan solo con su icono entre 768 y 1199 px y «Editar» lleva el data-testid del browser test; en el
    celular siempre llevan su texto y no repiten el data-testid. Todo docente de la lista es del alcance de quien la
    ve (TeacherQuery), así que sus acciones están habilitadas; cada método vuelve a autorizar con TeacherPolicy. No
    hay «Borrar»: un docente se da de baja, y su usuario con él.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <a
        href="{{ route('faculty.teachers.show', $teacher->id) }}"
        wire:navigate
        class="btn btn-ghost btn-sm"
        aria-label="Ficha de {{ $teacher->employee_number }}"
        @if ($inTable) data-testid="record-{{ $teacher->employee_number }}" @endif
    >
        <x-icon name="o-identification" class="h-4 w-4" aria-hidden="true" />
        <span @class(['max-[1200px]:hidden' => $inTable])>Ficha</span>
    </a>

    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $teacher->id }}')"
        aria-label="Editar {{ $teacher->employee_number }}"
        :data-testid="$inTable ? 'edit-'.$teacher->employee_number : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $teacher->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-core::row-menu :name="$teacher->employee_number" :compact="$inTable">
        <x-core::row-menu-item
            :label="$teacher->is_active ? 'Desactivar' : 'Activar'"
            wire:click="toggleActive('{{ $teacher->id }}')"
            wire:confirm="{{ $teacher->is_active ? '¿Dar de baja al docente '.$teacher->employee_number.'? Se desactiva su usuario y terminan sus jefaturas vigentes.' : '¿Activar al docente '.$teacher->employee_number.'? Su usuario vuelve a estar activo.' }}"
            :spinner="'toggleActive(\''.$teacher->id.'\')'"
        />
    </x-core::row-menu>
</div>
