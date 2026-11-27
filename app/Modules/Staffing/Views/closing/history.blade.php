{{--
    El historial de una carga (`load_transitions`, HC-18), del más reciente al más antiguo, como riel (`border-s-2`) con
    un punto por paso: el título («Cerrada», «Reabierta», «Volvió a Asignación»), de qué estado a cuál, quién y cuándo, y
    el comentario o el motivo en `bg-base-200`. `$closing`.
--}}
@if ($closing->history === [])
    <p class="px-5 py-4 text-sm text-neutral">Sin cambios de estado.</p>
@else
    <ol class="px-5 py-4" data-testid="load-history">
        @foreach ($closing->history as $entry)
            <li class="relative border-s-2 border-base-300 ps-5 pb-5 last:border-transparent last:pb-0">
                @php($tone = \App\Modules\Staffing\Queries\LoadClosing::stepTone($entry))
                <span @class(['absolute -start-[7px] top-1 h-3 w-3 rounded-full ring-4 ring-base-100', 'bg-success' => $tone === 'success', 'bg-warning' => $tone === 'warning', 'bg-base-content/30' => $tone === 'neutral']) aria-hidden="true"></span>
                <p class="font-semibold leading-snug">{{ \App\Modules\Staffing\Queries\LoadClosing::stepTitle($entry) }}</p>
                <p class="text-[12.5px] text-neutral">
                    {{ $entry['from']->label() }} → {{ $entry['to']->label() }} · {{ $entry['by'] }} · <span class="whitespace-nowrap font-mono tabular-nums">{{ $entry['at']->format('d/m/Y G:i') }}</span>
                </p>
                @if ($entry['comment'])
                    <p class="mt-1.5 rounded-field bg-base-200 px-3 py-2 text-sm">{{ $entry['comment'] }}</p>
                @endif
            </li>
        @endforeach
    </ol>
@endif
