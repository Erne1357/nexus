{{--
    Acciones de un registro de la lista (DESIGN.md, "Acciones de fila" y «Revisión por registro»), las mismas en la tabla
    y en los renglones del celular: una visible y «Acciones» con lo demás.

    - Docentes: «Dar de baja» visible (CV8): con `x-confirm` que dice qué pasa según le quede o no otro departamento; si
      no se puede, deshabilitado y con su motivo escrito en la fila (CV-S8: ocupa un puesto), no en un tooltip (de la
      opción C, por el usuario el 12 de noviembre de 2026). Si el motivo es de toda la pantalla (solo lectura, revisión
      validada), lo dice el renglón de la revisión una vez. En «Acciones»: ficha, disponibilidad, el correo institucional
      (abre su recuadro), «Observaciones» (el drawer, también en lectura), «Agregar observación» y «Quitar «Revisado»».
    - Lo demás: «Editar» visible (o «Ver» si el usuario no corrige), que abre el drawer; en «Acciones», «Agregar
      observación», «Quitar «Revisado»» y «Activar/Desactivar» (materias y salones) o su motivo.

    Lo que el rol o el estado no permite se queda en el menú, deshabilitado y con su motivo escrito. Enter en una fila
    abre `data-row-enter`. `$record`, `$inTable`; `$current`, `$marks` y `$teacherRows` son de review.php.
--}}
@php($code = $record->code ?? $record->rfc)
@php($teachers = $current['review']->catalog === \App\Modules\CatalogValidation\Enums\Catalog::Teachers)
@php($row = $teacherRows[$record->id] ?? null)
@php($mark = $marks[$record->id] ?? null)
@php($rowReason = $teachers && $row['dischargeReason'] !== null && $row['dischargeReason'] !== $current['editReason'] ? $row['dischargeReason'] : null)
<div @class(['flex flex-col gap-1', 'items-end whitespace-nowrap' => $inTable])>
    <div @class(['flex items-center gap-1', 'justify-end' => $inTable])>
        @if ($teachers)
            @if ($row['dischargeReason'] === null)
                <x-button
                    icon="o-user-minus"
                    wire:click="dischargeTeacher('{{ $record->id }}')"
                    x-confirm="{{ $row['confirmMessage'] }}"
                    data-confirm-title="{{ $row['confirmTitle'] }}"
                    data-confirm-button="Dar de baja"
                    data-confirm-tone="error"
                    aria-label="Dar de baja a {{ $record->name }}"
                    :data-testid="$inTable ? 'discharge-'.$code : null"
                    class="btn-ghost btn-sm max-md:h-11"
                    spinner="dischargeTeacher('{{ $record->id }}')"
                >
                    <span @class(['max-[1199px]:hidden' => $inTable])>Dar de baja</span>
                </x-button>
            @else
                <button type="button" class="btn btn-ghost btn-sm max-md:h-11" aria-disabled="true" disabled aria-label="Dar de baja a {{ $record->name }}: {{ $row['dischargeReason'] }}">
                    <x-icon name="o-user-minus" class="h-4 w-4" aria-hidden="true" /><span @class(['max-[1199px]:hidden' => $inTable])>Dar de baja</span>
                </button>
            @endif
        @else
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
        @endif

        <x-core::row-menu :name="$code" :compact="$inTable">
            @if ($teachers)
                <x-core::row-menu-item label="Ficha" :href="route('faculty.teachers.show', $record->id)" :data-testid="$inTable ? 'record-'.$code : null" />
                <x-core::row-menu-item label="Disponibilidad" :href="route('faculty.teachers.availability', $record->id)" />
                @if ($row['emailReason'] === null)
                    <x-core::row-menu-item
                        :label="$record->institutional_email ? 'Cambiar correo institucional' : 'Agregar correo institucional'"
                        data-email-open="{{ $record->id }}"
                        data-email-name="{{ $record->name }}"
                        data-email-current="{{ $record->institutional_email }}"
                        :data-testid="$inTable ? 'email-'.$code : null"
                    />
                @else
                    <x-core::row-menu-item :label="$record->institutional_email ? 'Cambiar correo institucional' : 'Agregar correo institucional'" disabled :reason="$row['emailReason']" />
                @endif
                <x-core::row-menu-item
                    label="Observaciones"
                    wire:click="edit('{{ $record->id }}')"
                    :spinner="'edit(\''.$record->id.'\')'"
                    :data-testid="$inTable ? 'observations-'.$code : null"
                />
            @endif

            @if ($current['canFlag'])
                <x-core::row-menu-item
                    label="Agregar observación"
                    wire:click="edit('{{ $record->id }}')"
                    :spinner="'edit(\''.$record->id.'\')'"
                    :data-testid="$inTable ? 'flag-'.$code : null"
                />
            @else
                <x-core::row-menu-item label="Agregar observación" disabled :reason="$current['flagReason']" />
            @endif

            @if ($mark !== null && $current['canMark'])
                <x-core::row-menu-item
                    label="Quitar «Revisado»"
                    :reason="$mark['long']"
                    wire:click="setReviewed('{{ $record->id }}', false)"
                    :spinner="'setReviewed(\''.$record->id.'\', false)'"
                    :data-testid="$inTable ? 'unmark-'.$code : null"
                />
            @endif

            @if (! $teachers)
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

    @if ($rowReason !== null)
        <p @class(['text-xs text-neutral', 'w-56 whitespace-normal text-end' => $inTable, 'max-w-72' => ! $inTable]) @if ($inTable) data-testid="discharge-reason-{{ $code }}" @endif>{{ $rowReason }}</p>
    @endif
</div>
