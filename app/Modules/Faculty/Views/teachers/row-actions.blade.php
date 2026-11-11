{{--
    Acciones de un docente, las mismas en la tabla y en los renglones apilados del celular (faculty::pages.teachers):
    «Ficha» (el enlace a la ficha del docente) y «Editar» visibles y lo demás plegado en «Acciones» (DESIGN.md, "Acciones de fila"). En la tabla (`$inTable`), los
    botones quedan solo con su icono entre 768 y 1199 px y «Editar» lleva el data-testid del browser test; en el
    celular siempre llevan su texto y no repiten el data-testid.

    `$actions` (del componente, ya decidido con TeacherPolicy): un «Quitar de <depto>» por cada adscripción a un
    departamento que cubre, con su aviso de qué principal queda (las de otros departamentos no se muestran), y el
    motivo por el que «Desactivar/Activar» está deshabilitado, o null. No hay «Borrar»: un docente se da de baja.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <a
        href="{{ route('faculty.teachers.show', $teacher->id) }}"
        wire:navigate
        class="btn btn-ghost btn-sm"
        aria-label="Ficha de {{ $teacher->rfc }}"
        @if ($inTable) data-testid="record-{{ $teacher->rfc }}" @endif
    >
        <x-icon name="o-identification" class="h-4 w-4" aria-hidden="true" />
        <span @class(['max-[1200px]:hidden' => $inTable])>Ficha</span>
    </a>

    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $teacher->id }}')"
        aria-label="Editar {{ $teacher->rfc }}"
        :data-testid="$inTable ? 'edit-'.$teacher->rfc : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $teacher->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-core::row-menu :name="$teacher->rfc" :compact="$inTable">
        @foreach ($actions['removals'] as $removal)
            @if ($removal['reason'] === null)
                <x-core::row-menu-item
                    :label="$removal['label']"
                    wire:click="removeAdscription('{{ $removal['id'] }}')"
                    x-confirm="{{ $removal['confirm'] }}"
                    data-confirm-title="{{ $removal['title'] }}"
                    data-confirm-button="Quitar"
                    data-confirm-tone="error"
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
                x-confirm="{{ $teacher->is_active ? 'Se desactiva su usuario y terminan sus jefaturas vigentes.' : 'Su usuario vuelve a estar activo.' }}"
                data-confirm-title="{{ $teacher->is_active ? '¿Dar de baja al docente '.$teacher->rfc.'?' : '¿Activar al docente '.$teacher->rfc.'?' }}"
                data-confirm-button="{{ $teacher->is_active ? 'Dar de baja' : 'Activar' }}"
                data-confirm-tone="{{ $teacher->is_active ? 'error' : 'primary' }}"
                :spinner="'toggleActive(\''.$teacher->id.'\')'"
            />
        @else
            <x-core::row-menu-item :label="$teacher->is_active ? 'Desactivar' : 'Activar'" :reason="$actions['deactivateReason']" disabled />
        @endif
    </x-core::row-menu>
</div>
