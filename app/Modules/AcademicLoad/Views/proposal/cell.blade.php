{{--
    Una cifra que se captura o se lee en la tabla de la propuesta (academic-load::proposal.table): los esperados o los
    grupos de una fila. Es un campo que guarda al salir (`saveCell`) si la columna es del usuario (M7) y la fila no está
    bloqueada por su estado; si no, el número como texto, no un campo deshabilitado. En la columna de un campus inactivo,
    los grupos pedidos llevan «Dejar en 0» para quien envía la propuesta (M14). `$prefix` separa los ids de la
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
    @if ($readonly && $field === 'requested_groups' && ($column['zeroable'] ?? false) && ! isset($locks[$item->id]) && (int) $value > 0)
        {{-- M14: la columna de un campus inactivo solo deja los grupos en cero. --}}
        <span class="flex items-center justify-end gap-1.5">
            <span class="font-mono text-sm tabular-nums" data-readonly-cell="{{ $field }}"><span class="sr-only">{{ $fieldLabel }}{{ $where ? ' en '.$where : '' }}: </span>{{ $value }}</span>
            <button type="button" class="btn btn-ghost btn-xs h-7 min-h-7 px-1.5 text-xs max-md:h-11 max-md:min-h-11" wire:click="zeroRow('{{ $item->id }}')" wire:loading.attr="disabled" wire:target="zeroRow('{{ $item->id }}')"
                aria-label="Dejar en 0 los grupos de {{ $subjectCode }} en {{ $where }}" data-testid="{{ $prefix === 'cell' ? 'zero-row-'.$item->id : 'card-zero-row-'.$item->id }}">Dejar en 0</button>
        </span>
        @if ($cellError)
            <span class="text-xs text-error">{{ $cellError }}</span>
        @endif
    @elseif ($readonly)
        <span class="text-right font-mono text-sm tabular-nums" data-readonly-cell="{{ $field }}"><span class="sr-only">{{ $fieldLabel }}{{ $where ? ' en '.$where : '' }}: </span>{{ $value }}</span>
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
