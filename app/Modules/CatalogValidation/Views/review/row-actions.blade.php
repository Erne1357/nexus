{{--
    Acciones de un registro de la lista (DESIGN.md, "Acciones de fila" y «Revisión por registro»), las mismas en la tabla
    y en los renglones del celular: una visible y «Acciones» con lo demás.

    - Docentes: la acción visible es «Marcar revisado», en la columna «Revisión». En «Acciones»: ficha, disponibilidad, el
      correo institucional (abre su recuadro, también con la revisión validada: CV-S2, D11), «Observaciones» (el drawer,
      también en lectura), «Agregar observación», «Quitar «Revisado»» y, al final, «Quitar de <departamento>» (CV8, D8 y
      D10), que abre el modal con la consecuencia y el motivo opcional; si no se puede (CV-S8: ocupa un puesto), queda
      deshabilitado con su motivo escrito en su segundo renglón.
    - Lo demás: «Editar» visible (o «Ver» si el usuario no corrige), que abre el drawer; en «Acciones», «Agregar
      observación», «Quitar «Revisado»» y «Activar/Desactivar» (materias y salones) o su motivo.

    Lo que el rol o el estado no permite se queda en el menú, deshabilitado y con su motivo escrito; a quien solo mira
    (Subdirección, el admin) no se le muestra (tarea 1.21). Enter en una fila abre `data-row-enter`. `$record`,
    `$inTable`; `$current`, `$marks` y `$teacherRows` son de review.php.
--}}
@php($code = $record->code ?? $record->rfc)
@php($teachers = $current['review']->catalog === \App\Modules\CatalogValidation\Enums\Catalog::Teachers)
@php($row = $teacherRows[$record->id] ?? null)
@php($mark = $marks[$record->id] ?? null)
@php($viewOnly = $current['viewOnly'])
{{-- CV14: un docente inactivo no se valida: solo ficha, disponibilidad y ver. --}}
@php($inactive = $teachers && ! $record->is_active)
{{-- 1.24.3: las páginas a las que se sale de aquí ofrecen «Regresar» a este departamento y catálogo. --}}
@php($back = \App\Modules\Core\Support\ValidationReturn::params($current['review']->department_id, $current['review']->catalog->value))
@php($departmentName = $current['review']->department?->name)
<div @class(['flex items-center gap-1', 'justify-end whitespace-nowrap' => $inTable])>
    @unless ($teachers)
        <x-button
            :icon="$current['canEdit'] ? 'o-pencil-square' : 'o-eye'"
            wire:click="edit('{{ $record->id }}')"
            aria-label="{{ $current['canEdit'] ? 'Editar' : 'Ver' }} {{ $code }}"
            :data-testid="$inTable ? 'edit-'.$code : null"
            data-row-enter
            class="btn-ghost btn-sm max-md:h-11"
            spinner="edit('{{ $record->id }}')"
        >
            <span @class(['max-[1199px]:hidden' => $inTable])>{{ $current['canEdit'] ? 'Editar' : 'Ver' }}</span>
        </x-button>
    @endunless

    <x-core::row-menu :name="$code" :compact="$inTable">
        @if ($teachers)
            <x-core::row-menu-item label="Ficha" :href="route('faculty.teachers.show', ['teacher' => $record->id, ...$back])" :data-testid="$inTable ? 'record-'.$code : null" />
            <x-core::row-menu-item label="Disponibilidad" :href="route('faculty.teachers.availability', ['teacher' => $record->id, ...$back])" />
            @if ($inactive)
                {{-- CV14: sin correo, observaciones ni baja. --}}
            @elseif ($row['emailReason'] === null)
                <x-core::row-menu-item
                    :label="$record->institutional_email ? 'Cambiar correo institucional' : 'Agregar correo institucional'"
                    data-email-open="{{ $record->id }}"
                    data-email-name="{{ $record->name }}"
                    data-email-current="{{ $record->institutional_email }}"
                    :data-testid="$inTable ? 'email-'.$code : null"
                />
            @elseif (! $viewOnly)
                <x-core::row-menu-item :label="$record->institutional_email ? 'Cambiar correo institucional' : 'Agregar correo institucional'" disabled :reason="$row['emailReason']" />
            @endif
            <x-core::row-menu-item
                label="{{ $inactive ? 'Ver observaciones' : 'Observaciones' }}"
                wire:click="edit('{{ $record->id }}')"
                :spinner="'edit(\''.$record->id.'\')'"
                :data-testid="$inTable ? 'observations-'.$code : null"
            />
        @endif

        @if ($inactive)
            {{-- CV14 --}}
        @elseif ($current['canFlag'])
            <x-core::row-menu-item
                label="Agregar observación"
                wire:click="edit('{{ $record->id }}')"
                :spinner="'edit(\''.$record->id.'\')'"
                :data-testid="$inTable ? 'flag-'.$code : null"
            />
        @elseif (! $viewOnly)
            <x-core::row-menu-item label="Agregar observación" disabled :reason="$current['flagReason']" />
        @endif

        @if ($mark !== null && $current['canMark'] && ! $inactive)
            <x-core::row-menu-item
                label="Quitar «Revisado»"
                :reason="$mark['long']"
                wire:click="setReviewed('{{ $record->id }}', false)"
                :spinner="'setReviewed(\''.$record->id.'\', false)'"
                :data-testid="$inTable ? 'unmark-'.$code : null"
            />
        @endif

        @if ($teachers && ! $viewOnly && ! $inactive)
            @if ($row['dischargeReason'] === null)
                <x-core::row-menu-item
                    :label="'Quitar de '.$departmentName"
                    danger
                    wire:click="askDischarge('{{ $record->id }}')"
                    :spinner="'askDischarge(\''.$record->id.'\')'"
                    :data-testid="$inTable ? 'discharge-'.$code : null"
                />
            @else
                <x-core::row-menu-item :label="'Quitar de '.$departmentName" disabled :reason="$row['dischargeReason']" />
            @endif
        @endif

        @if (! $teachers && ! $viewOnly)
            @if ($current['canToggle'])
                <x-core::row-menu-item
                    :label="$record->is_active ? 'Desactivar' : 'Activar'"
                    :danger="$record->is_active"
                    wire:click="toggleActive('{{ $record->id }}')"
                    x-confirm="{{ $record->is_active ? 'Deja de ofrecerse en el catálogo.' : 'Vuelve a ofrecerse en el catálogo.' }}"
                    data-confirm-title="{{ $record->is_active ? '¿Desactivar '.$code.'?' : '¿Activar '.$code.'?' }}"
                    data-confirm-button="{{ $record->is_active ? 'Desactivar' : 'Activar' }}"
                    data-confirm-tone="{{ $record->is_active ? 'error' : 'primary' }}"
                    :spinner="'toggleActive(\''.$record->id.'\')'"
                />
            @else
                <x-core::row-menu-item :label="$record->is_active ? 'Desactivar' : 'Activar'" disabled :reason="$current['toggleReason']" />
            @endif
        @endif
    </x-core::row-menu>
</div>
