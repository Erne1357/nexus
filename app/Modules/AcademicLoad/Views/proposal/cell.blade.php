{{--
    Una cifra que se captura o se lee en la tabla de la propuesta (academic-load::proposal.table): los esperados o los
    grupos de una fila. Es un campo que guarda al salir (`saveCell`) si la columna es del usuario (M7) y la fila no está
    bloqueada por su estado; si no, el número como texto, no un campo deshabilitado. `$prefix` separa los ids de la
    forma ancha (`cell`) y de la ficha (`card-cell`); `data-cell` es el mismo en las dos para marcar lo guardado.
--}}
@php
    $cell = "{$item->id}.{$field}";
    $cellError = $cellErrors[$cell] ?? null;
    $readonly = isset($locks[$item->id]) || ! $column['editable'];
    $where = $column['campus']?->code;
    $suffix = count($columns) > 1 && $where !== null ? "-{$where}" : '';
@endphp
<div class="group/cell flex min-w-0 flex-col gap-0.5" data-cell="{{ $cell }}">
    @if ($readonly)
        <span class="text-right font-mono text-sm tabular-nums" data-readonly-cell="{{ $field }}">{{ $value }}</span>
    @else
        <div class="relative">
            <input type="text" id="{{ $prefix }}-{{ $item->id }}-{{ $field }}" inputmode="numeric" pattern="[0-9]*" maxlength="{{ strlen((string) $max) }}" autocomplete="off" value="{{ $value }}"
                oninput="this.value = this.value.replace(/\D/g, '')" wire:change="saveCell('{{ $item->id }}', '{{ $field }}', $event.target.value)"
                aria-label="{{ $fieldLabel }}{{ $where ? ' en '.$where : '' }} de {{ $subjectCode }}" @if ($cellError) aria-invalid="true" aria-describedby="{{ $prefix }}-error-{{ $item->id }}-{{ $field }}" @endif
                @class(['input input-sm w-full px-2 text-right font-mono text-sm tabular-nums group-data-[saved]/cell:input-success', 'input-error' => $cellError]) data-field="{{ $field }}" />
            {{-- En un span: el icono de MaryUI trae `inline`, que le gana a `hidden`. --}}
            <span class="pointer-events-none absolute start-1.5 top-1/2 hidden -translate-y-1/2 text-success group-data-[saved]/cell:block" aria-hidden="true"><x-icon name="o-check" class="block h-4 w-4" /></span>
        </div>
        <span class="sr-only" role="status" data-saved-status></span>
        @if ($cellError)
            <span id="{{ $prefix }}-error-{{ $item->id }}-{{ $field }}" class="text-xs text-error" data-testid="cell-error-{{ $subjectCode }}{{ $suffix }}-{{ $field }}">{{ $cellError }}</span>
        @endif
    @endif
</div>
