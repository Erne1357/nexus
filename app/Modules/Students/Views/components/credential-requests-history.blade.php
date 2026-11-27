{{--
    El historial de solicitudes de credencial de un alumno (CO-07, CO-10), la más reciente primero: qué pidió, el motivo de la reposición (CO-12), el estado,
    cuándo y, si la rechazaron, el motivo. Lo usan «Mis datos › Credencial» y, después, la ficha del alumno.

    $requests: CredentialRequestQuery::history().
--}}
@props(['requests'])

<div data-testid="credential-requests-history">
    @if ($requests->isEmpty())
        <p class="rounded-box border border-base-300 bg-base-100 p-5 text-neutral" data-testid="credential-requests-empty">Todavía no hay solicitudes de credencial.</p>
    @else
        <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300 bg-base-100">
            @foreach ($requests as $request)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3" data-testid="credential-request-row">
                    <span class="badge badge-soft font-medium">{{ $request->status->label() }}</span>
                    <span class="font-medium">{{ collect([$request->wants_reposition ? 'Reposición de credencial' : null, $request->wants_photo ? 'Foto' : null])->filter()->implode(' y ') }}</span>
                    <span class="font-mono text-sm text-neutral tabular-nums">{{ $request->requested_at->setTimezone('America/Ciudad_Juarez')->locale('es')->isoFormat('D MMM YYYY') }}</span>
                    @if ($request->reposition_reason !== null)
                        <span class="w-full text-sm text-neutral" data-testid="credential-request-reason">Motivo: {{ $request->reposition_reason->label() }}</span>
                    @endif
                    @if ($request->status === \App\Modules\Students\Enums\CredentialRequestStatus::Rechazada && $request->rejection_reason)
                        <span class="w-full text-sm text-neutral">Motivo: {{ $request->rejection_reason }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
