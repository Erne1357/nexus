{{--
    Si un registro ya está revisado (CV7), en su fila de la lista: con permiso y la revisión abierta, un interruptor
    «Revisado» que marca o desmarca el registro (setReviewed, con el estado que se pide); si no, solo la insignia, con el motivo en su título.
    `$card` es de review.php y `$reviewedMap` trae como llaves los registros de la página ya revisados. `$inTable` decide
    si el texto del interruptor solo va para lectores de pantalla, porque la columna ya dice «Revisado».
--}}
@php($isReviewed = isset($reviewedMap[$record->id]))
@php($code = $record->code ?? $record->rfc)
@if ($card['canMark'])
    <label class="flex cursor-pointer items-center gap-2 text-sm">
        <input type="checkbox" class="toggle toggle-sm toggle-success" @checked($isReviewed) wire:click="setReviewed('{{ $record->id }}', {{ $isReviewed ? 'false' : 'true' }})" aria-label="{{ $code }} revisado" @if ($inTable) data-testid="reviewed-{{ $code }}" @endif>
        <span @class(['sr-only' => $inTable])>Revisado</span>
    </label>
@else
    <x-core::status-badge :tone="$isReviewed ? 'success' : 'neutral'" :label="$isReviewed ? 'Revisado' : 'Sin revisar'" :title="$card['markReason']" />
@endif
