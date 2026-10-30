{{--
    Acciones de un docente, las mismas en la tabla y en los renglones apilados del celular (faculty::pages.teachers):
    «Editar» visible y lo demás plegado en «Acciones» (DESIGN.md, "Acciones de fila"). En la tabla (`$inTable`), los
    botones quedan solo con su icono entre 768 y 1199 px y «Editar» lleva el data-testid del browser test; en el
    celular siempre llevan su texto y no repiten el data-testid.

    `$actions` (del componente, ya decidido con TeacherPolicy): un «Quitar de <depto>» por cada adscripción a un
    departamento que cubre, con su aviso de qué principal queda (las de otros departamentos no se muestran), y el
    motivo por el que «Desactivar/Activar» está deshabilitado, o null. No hay «Borrar»: un docente se da de baja.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
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
        @foreach ($actions['removals'] as $removal)
            @if ($removal['reason'] === null)
                <x-core::row-menu-item
                    :label="$removal['label']"
                    wire:click="removeAdscription('{{ $removal['id'] }}')"
                    wire:confirm="{{ $removal['confirm'] }}"
                    :spinner="'removeAdscription(\''.$removal['id'].'\')'"
                />
            @else
                <x-core::row-menu-item :label="$removal['label']" :reason="$removal['reason']" disabled />
            @endif
        @endforeach

        @if ($actions['deactivateReason'] === null)
            <x-core::row-menu-item
                :label="$teacher->is_active ? 'Desactivar' : 'Activar'"
                wire:click="toggleActive('{{ $teacher->id }}')"
                wire:confirm="{{ $teacher->is_active ? '¿Dar de baja al docente '.$teacher->employee_number.'? Se desactiva su usuario y terminan sus jefaturas vigentes.' : '¿Activar al docente '.$teacher->employee_number.'? Su usuario vuelve a estar activo.' }}"
                :spinner="'toggleActive(\''.$teacher->id.'\')'"
            />
        @else
            <x-core::row-menu-item :label="$teacher->is_active ? 'Desactivar' : 'Activar'" :reason="$actions['deactivateReason']" disabled />
        @endif
    </x-core::row-menu>
</div>
