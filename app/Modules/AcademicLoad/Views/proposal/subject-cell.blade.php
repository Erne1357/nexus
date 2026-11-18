{{--
    La materia de un renglón de la tabla de la propuesta (academic-load::proposal.table), en las dos formas: su clave
    con el icono del estado de la parte de su departamento, su nombre con la clave del departamento y, debajo, lo que
    hay que ver: el bloqueo propio («CB ya aceptó su parte»), las notas de cada campus y las anomalías de cada fila, con
    su campus si hay más de uno. `$row` es la ProposalSubject, `$code` la clave del departamento y `$part` su estado.

    Con la proyección (tarea 5.6, opción C), la marca de sus filas en las columnas del usuario (ProposalForecastReview::mark),
    con icono, texto para lectores de pantalla y `title`: «Sugerido» (sigue a la proyección), «Corregido» o «Cambió» antes de
    la clave; o, si tiene algo por revisar y el usuario lo puede revisar, «Revisar: <motivo> y N más» bajo el nombre, que
    lleva a su tarjeta (`reviewItem`). Lleva `data-forecast-mark` para las pruebas; en la ficha angosta (`$inCard`) sin
    repetir el `data-testid`.
--}}
@php
    $anomalies = $overview->subjectAnomalies($row);
    $firstLock = collect($row->items())->map(fn ($item) => $locks[$item->id] ?? null)->filter()->first();
    $ownLock = $firstLock !== null && ! $firstLock->isGeneral() ? $firstLock->short($code) : null;
    $notes = $row->notes();
    $forecastMark = $projection->hasForecast() ? $projection->mark($row, $reviewKeys) : ['state' => null, 'large' => false, 'review' => null];
    $reviewChip = $canReview ? $forecastMark['review'] : null;
    $stateMark = $reviewChip !== null ? null : match ($forecastMark['state']) {
        'suggested' => ['o-sparkles', 'Sugerido', 'Sugerido por Nexus: sigue a la proyección', 'text-neutral'],
        'captured' => ['o-pencil', 'Corregido', 'Corregido: ya no sigue sola a la proyección', 'text-neutral'],
        'changed' => ['o-arrow-path', 'Cambió', 'La proyección cambió después de corregirla', 'text-info'],
        default => null,
    };
@endphp
<div class="min-w-0">
    <span class="flex items-center gap-1.5 font-mono text-sm font-semibold">
        @if ($stateMark)
            <span class="inline-flex shrink-0 {{ $stateMark[3] }}" title="{{ $stateMark[2] }}" data-forecast-mark="{{ $forecastMark['state'] }}"><x-icon :name="$stateMark[0]" class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">{{ $stateMark[1] }}.</span></span>
        @endif
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
    @if ($reviewChip)
        @php
            $chipReason = $reviewChip['reason'];
            $chipTone = match ($chipReason) {
                \App\Modules\AcademicLoad\Enums\ForecastReason::Changed => 'border-info/40 bg-info/10 text-info',
                \App\Modules\AcademicLoad\Enums\ForecastReason::LargeDifference => 'border-warning/40 bg-warning/10 text-warning',
                default => 'border-base-300 bg-base-200 text-neutral',
            };
        @endphp
        <button type="button" wire:click="reviewItem('{{ $reviewChip['item'] }}')" data-forecast-mark="review" @if (! ($inCard ?? false)) data-testid="review-chip-{{ $row->subject->code }}" @endif
            class="mt-1 inline-flex min-h-6 max-w-full items-center gap-1.5 rounded-selector border px-2 py-0.5 text-left text-[12.5px] font-medium hover:underline hover:underline-offset-2 max-md:min-h-11 {{ $chipTone }}">
            <x-icon :name="$chipReason->icon()" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>Revisar: {{ mb_strtolower($chipReason->label()) }}{{ $reviewChip['more'] > 0 ? ' y '.$reviewChip['more'].' más' : '' }}</span>
        </button>
    @endif
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
                <span class="flex items-start gap-1 text-warning" data-testid="anomaly-{{ $row->subject->code }}">
                    <x-icon name="o-exclamation-triangle" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span><span class="sr-only">Revisar: </span>{{ implode('; ', $anomalies) }}</span>
                </span>
            @endif
        </span>
    @endif
</div>
