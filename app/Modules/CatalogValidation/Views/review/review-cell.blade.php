{{--
    La columna «Revisión» de un registro (CV7; DESIGN.md, «Revisión por registro»): un inactivo sin marca no se revisa y
    lo dice; a quien marca, «Marcar revisado» si falta (setReviewed, con el estado que se pide; también lo saca de la selección) o la insignia «Revisado» con quién y
    cuándo; a quien no marca (CV-S1) o con la revisión validada, solo la insignia: «Revisado» con quién y cuándo, o «Por
    revisar». Desmarcar está en «Acciones». `$inTable` decide el data-testid (los renglones apilados no lo repiten);
    `$marks` y `$current` son de review.php.
--}}
@php($mark = $marks[$record->id] ?? null)
@php($code = $record->code ?? $record->rfc)
@if ($mark === null && ! $record->is_active)
    <span class="text-[12.5px] text-neutral">Inactivo: no se revisa</span>
@elseif ($mark === null && $current['canMark'])
    <button type="button" class="btn btn-outline btn-sm max-md:h-11" wire:click="setReviewed('{{ $record->id }}', true)" x-on:click="forget('{{ $record->id }}')" wire:loading.attr="disabled" wire:target="setReviewed('{{ $record->id }}', true)" aria-label="Marcar {{ $code }} como revisado" @if ($inTable) data-testid="mark-{{ $code }}" @endif>
        <x-icon name="o-check" class="h-4 w-4" aria-hidden="true" />Marcar revisado
    </button>
@elseif ($mark !== null)
    <span class="inline-flex flex-col items-start gap-0.5">
        <x-core::status-badge tone="success" label="Revisado" />
        <span class="text-[12.5px] text-neutral tabular-nums">{{ $mark['short'] }}</span>
    </span>
@else
    <x-core::status-badge tone="draft" icon="o-clock" label="Por revisar" />
@endif
