{{-- Las observaciones abiertas de una fila: el número abre el drawer; sin ninguna, un guion. --}}
@if ($row['open_flags'] > 0)
    <button type="button" wire:click="open('{{ $row['department']->id }}')" class="font-mono font-semibold tabular-nums underline underline-offset-4" aria-label="{{ $row['open_flags'] }} observaciones abiertas de {{ $row['department']->name }}">{{ $row['open_flags'] }}</button>
@else
    <span class="text-neutral" aria-label="Sin observaciones abiertas">0</span>
@endif
