{{--
    Acciones de un registro de la lista (DESIGN.md, "Acciones de fila"), las mismas en la tabla y en los renglones del
    celular: «Editar» (o «Ver», si el usuario no corrige; en docentes, «Ficha», porque no se editan aquí) visible, y
    «Acciones» con «Agregar observación» y «Activar/Desactivar». Lo que el rol o el estado no permite se queda en el
    menú, deshabilitado y con su motivo escrito. `$card` es de review.php.
--}}
@php($code = $record->code ?? $record->employee_number)
@php($teachers = $card['review']->catalog === \App\Modules\CatalogValidation\Enums\Catalog::Teachers)
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    @if ($teachers)
        <a href="{{ route('faculty.teachers.show', $record->id) }}" wire:navigate class="btn btn-ghost btn-sm" aria-label="Ficha de {{ $code }}" @if ($inTable) data-testid="record-{{ $code }}" @endif>
            <x-icon name="o-identification" class="h-4 w-4" aria-hidden="true" />
            <span @class(['max-[1200px]:hidden' => $inTable])>Ficha</span>
        </a>
        <x-button
            icon="o-chat-bubble-left-ellipsis"
            wire:click="edit('{{ $record->id }}')"
            aria-label="Observaciones de {{ $code }}"
            :data-testid="$inTable ? 'observations-'.$code : null"
            class="btn-ghost btn-sm"
            spinner="edit('{{ $record->id }}')"
        >
            <span @class(['max-[1200px]:hidden' => $inTable])>Observaciones</span>
        </x-button>
    @else
        <x-button
            :icon="$card['canEdit'] ? 'o-pencil-square' : 'o-eye'"
            wire:click="edit('{{ $record->id }}')"
            aria-label="{{ $card['canEdit'] ? 'Editar' : 'Ver' }} {{ $code }}"
            :data-testid="$inTable ? 'edit-'.$code : null"
            class="btn-ghost btn-sm"
            spinner="edit('{{ $record->id }}')"
        >
            <span @class(['max-[1200px]:hidden' => $inTable])>{{ $card['canEdit'] ? 'Editar' : 'Ver' }}</span>
        </x-button>
    @endif

    <x-core::row-menu :name="$code" :compact="$inTable">
        @if ($card['canFlag'])
            <x-core::row-menu-item
                label="Agregar observación"
                wire:click="edit('{{ $record->id }}')"
                :spinner="'edit(\''.$record->id.'\')'"
                :data-testid="$inTable ? 'flag-'.$code : null"
            />
        @else
            <x-core::row-menu-item label="Agregar observación" disabled :reason="$card['flagReason']" />
        @endif

        @if ($card['canToggle'])
            <x-core::row-menu-item
                :label="$record->is_active ? 'Desactivar' : 'Activar'"
                wire:click="toggleActive('{{ $record->id }}')"
                wire:confirm="{{ $record->is_active ? '¿Desactivar '.$code.'? Deja de ofrecerse en el catálogo.' : '¿Activar '.$code.'?' }}"
                :spinner="'toggleActive(\''.$record->id.'\')'"
            />
        @else
            <x-core::row-menu-item :label="$record->is_active ? 'Desactivar' : 'Activar'" disabled :reason="$card['toggleReason']" />
        @endif
    </x-core::row-menu>
</div>
