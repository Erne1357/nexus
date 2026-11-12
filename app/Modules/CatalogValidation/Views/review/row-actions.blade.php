{{--
    Acciones de un registro de la lista (DESIGN.md, "Acciones de fila"), las mismas en la tabla y en los renglones del
    celular: una sola acción visible, con texto desde 1200 px y solo icono entre 768 y 1199 (en docentes,
    «Observaciones»; en lo demás «Editar», o «Ver» si el usuario no corrige), y «Acciones» con la ficha y la
    disponibilidad (docentes), «Agregar observación» y «Activar/Desactivar» o, en docentes, «Dar de baja de <depto>»
    (CV8; `$dischargeReasons` trae por docente el motivo por el que no se puede, o null). Lo que el rol o el estado no
    permite se queda en el menú, deshabilitado y con su motivo escrito. `$card` es de review.php.
--}}
@php($code = $record->code ?? $record->rfc)
@php($teachers = $card['review']->catalog === \App\Modules\CatalogValidation\Enums\Catalog::Teachers)
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    @if ($teachers)
        <x-button
            icon="o-chat-bubble-left-ellipsis"
            wire:click="edit('{{ $record->id }}')"
            aria-label="Observaciones de {{ $code }}"
            :data-testid="$inTable ? 'observations-'.$code : null"
            class="btn-ghost btn-sm"
            spinner="edit('{{ $record->id }}')"
        >
            <span @class(['max-[1199px]:hidden' => $inTable])>Observaciones</span>
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
            <span @class(['max-[1199px]:hidden' => $inTable])>{{ $card['canEdit'] ? 'Editar' : 'Ver' }}</span>
        </x-button>
    @endif

    <x-core::row-menu :name="$code" :compact="$inTable">
        @if ($teachers)
            <x-core::row-menu-item label="Ficha" :href="route('faculty.teachers.show', $record->id)" :data-testid="$inTable ? 'record-'.$code : null" />
            <x-core::row-menu-item label="Disponibilidad" :href="route('faculty.teachers.availability', $record->id)" />
        @endif

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

        @if ($teachers)
            @php($department = $card['review']->department?->code)
            @php($dischargeReason = array_key_exists($record->id, $dischargeReasons ?? []) ? $dischargeReasons[$record->id] : $card['editReason'])
            @if ($dischargeReason === null)
                <x-core::row-menu-item
                    :label="'Dar de baja de '.$department"
                    danger
                    wire:click="dischargeTeacher('{{ $record->id }}')"
                    x-confirm="Sale de {{ $department }}; si no está en otro departamento, queda dado de baja y su cuenta se inhabilita."
                    data-confirm-title="¿Dar de baja a {{ $code }} de {{ $department }}?"
                    data-confirm-button="Dar de baja"
                    data-confirm-tone="error"
                    :spinner="'dischargeTeacher(\''.$record->id.'\')'"
                    :data-testid="$inTable ? 'discharge-'.$code : null"
                />
            @else
                <x-core::row-menu-item :label="'Dar de baja de '.$department" disabled :reason="$dischargeReason" />
            @endif
        @elseif ($card['canToggle'])
            <x-core::row-menu-item
                :label="$record->is_active ? 'Desactivar' : 'Activar'"
                wire:click="toggleActive('{{ $record->id }}')"
                x-confirm="{{ $record->is_active ? 'Deja de ofrecerse en el catálogo.' : 'Vuelve a ofrecerse en el catálogo.' }}"
                data-confirm-title="{{ $record->is_active ? '¿Desactivar '.$code.'?' : '¿Activar '.$code.'?' }}"
                data-confirm-button="{{ $record->is_active ? 'Desactivar' : 'Activar' }}"
                data-confirm-tone="{{ $record->is_active ? 'error' : 'primary' }}"
                :spinner="'toggleActive(\''.$record->id.'\')'"
            />
        @else
            <x-core::row-menu-item :label="$record->is_active ? 'Desactivar' : 'Activar'" disabled :reason="$card['toggleReason']" />
        @endif
    </x-core::row-menu>
</div>
