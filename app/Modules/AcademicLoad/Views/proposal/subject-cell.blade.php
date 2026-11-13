{{--
    La materia de un renglón de la tabla de la propuesta (academic-load::proposal.table), en las dos formas: su clave
    con el icono del estado de la parte de su departamento, su nombre con la clave del departamento y, debajo, lo que
    hay que ver: el bloqueo propio («CB ya aceptó su parte»), las notas de cada campus y las anomalías de cada fila, con
    su campus si hay más de uno. `$row` es la ProposalSubject, `$code` la clave del departamento y `$part` su estado.
--}}
@php
    $anomalies = $overview->subjectAnomalies($row);
    $firstLock = collect($row->items())->map(fn ($item) => $locks[$item->id] ?? null)->filter()->first();
    $ownLock = $firstLock !== null && ! $firstLock->isGeneral() ? $firstLock->short($code) : null;
    $notes = $row->notes();
@endphp
<div class="min-w-0">
    <span class="flex items-center gap-1.5 font-mono text-sm font-semibold">
        @if ($part === 'returned')
            <x-icon name="o-arrow-uturn-left" class="h-4 w-4 shrink-0 text-error" aria-hidden="true" /><span class="sr-only">Regresada por {{ $code }}.</span>
        @elseif ($part === 'accepted')
            <x-icon :name="$generalLock === null ? 'o-lock-closed' : 'o-check-circle'" class="h-4 w-4 shrink-0" aria-hidden="true" /><span class="sr-only">Aceptada por {{ $code }}.</span>
        @endif
        {{ $row->subject->code }}
    </span>
</div>
<div class="min-w-0">
    <span class="line-clamp-2 text-sm" title="{{ $row->subject->name }}">{{ $row->subject->name }} <span class="font-mono text-xs text-neutral" title="{{ $row->subject->department->name }}">{{ $code }}</span></span>
    @if ($ownLock || $anomalies !== [] || $notes !== [])
        <span class="mt-0.5 flex flex-col gap-0.5 text-xs">
            @if ($ownLock)
                <span class="flex items-center gap-1"><x-icon name="o-lock-closed" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $ownLock }}</span>
            @endif
            @foreach ($notes as $key => $note)
                <span class="flex min-w-0 items-start gap-1" data-testid="note-{{ $row->subject->code }}">
                    <x-icon name="o-chat-bubble-left-ellipsis" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span class="line-clamp-2"><span class="font-medium">Nota{{ count($columns) > 1 && ($columns[$key]['campus'] ?? null) ? ' '.$columns[$key]['campus']->code : '' }}:</span> {{ $note }}</span>
                </span>
            @endforeach
            @if ($anomalies !== [])
                <span class="flex items-start gap-1 font-medium text-warning" data-testid="anomaly-{{ $row->subject->code }}">
                    <x-icon name="o-exclamation-triangle" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span><span class="sr-only">Revisar: </span>{{ implode('; ', $anomalies) }}</span>
                </span>
            @endif
        </span>
    @endif
</div>
