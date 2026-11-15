{{--
    Acciones de un aviso o un atajo (core::pages.login-content), las mismas en la tabla y en los renglones del celular:
    «Editar» visible y lo demás en «Acciones» (subir, bajar, desactivar o activar y borrar). Lo que el puesto no permite se
    muestra deshabilitado, con su motivo. `$row` es el aviso o el atajo; `$items` y `$loop` vienen de la página.
--}}
@php
    $first = $row->is($items->first());
    $last = $row->is($items->last());
    $filtered = $search !== '' || $status !== '';
@endphp
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    @can('update', $row)
        <x-button icon="o-pencil-square" wire:click="edit('{{ $row->id }}')" aria-label="Editar {{ $row->title }}" class="btn-ghost btn-sm" spinner="edit('{{ $row->id }}')">
            <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
        </x-button>
    @else
        <span class="tooltip tooltip-left" data-tip="No tienes permiso para editar.">
            <x-button icon="o-pencil-square" aria-label="Editar {{ $row->title }}" class="btn-ghost btn-sm" disabled>
                <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
            </x-button>
        </span>
    @endcan

    <x-core::row-menu :name="$row->title" :compact="$inTable">
        @can('update', $row)
            <x-core::row-menu-item label="Subir" wire:click="move('{{ $row->id }}', -1)" :disabled="$first || $filtered" :reason="$filtered ? 'Quita el filtro para cambiar el orden.' : ($first ? 'Ya es el primero.' : null)" />
            <x-core::row-menu-item label="Bajar" wire:click="move('{{ $row->id }}', 1)" :disabled="$last || $filtered" :reason="$filtered ? 'Quita el filtro para cambiar el orden.' : ($last ? 'Ya es el último.' : null)" />
            <x-core::row-menu-item
                :label="$row->is_active ? 'Desactivar' : 'Activar'"
                wire:click="toggleActive('{{ $row->id }}')"
                x-confirm="{{ $row->is_active ? 'Deja de verse en la pantalla de acceso hasta que lo actives.' : 'Vuelve a verse en la pantalla de acceso mientras esté vigente.' }}"
                data-confirm-title="{{ ($row->is_active ? '¿Desactivar «' : '¿Activar «').$row->title.'»?' }}"
                data-confirm-button="{{ $row->is_active ? 'Desactivar' : 'Activar' }}"
                data-confirm-tone="{{ $row->is_active ? 'error' : 'primary' }}"
                :spinner="'toggleActive(\''.$row->id.'\')'"
            />
        @else
            <x-core::row-menu-item label="Subir" reason="No tienes permiso para cambiar el orden." disabled />
            <x-core::row-menu-item :label="$row->is_active ? 'Desactivar' : 'Activar'" reason="No tienes permiso para cambiar el estado." disabled />
        @endcan

        @can('delete', $row)
            <x-core::row-menu-item
                label="Borrar"
                danger
                wire:click="delete('{{ $row->id }}')"
                x-confirm="Se quita de la pantalla de acceso y de esta lista. El cambio queda en la bitácora."
                data-confirm-title="¿Borrar «{{ $row->title }}»?"
                data-confirm-button="Borrar"
                data-confirm-tone="error"
                :spinner="'delete(\''.$row->id.'\')'"
            />
        @else
            <x-core::row-menu-item label="Borrar" reason="No tienes permiso para borrar." disabled />
        @endcan
    </x-core::row-menu>
</div>
