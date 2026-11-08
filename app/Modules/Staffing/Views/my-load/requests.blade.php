{{--
    «Mis solicitudes» de Mi carga (tarea 3.11, maqueta C): el título con cuántas lleva y, con el permiso de pedir, un solo
    «Solicitar cambio» que abre el modal (el grupo se elige ahí); deshabilitado con su motivo si ningún grupo se puede
    pedir. Si la carga de algún grupo no está en Asignación o Ajustes, una línea lo dice. Debajo, sus solicitudes del
    periodo: grupo, tipo, lo que propuso, estado, el comentario del jefe y la fecha. Con la caja de menos de 44rem, cada
    solicitud se apila.
--}}
@use('App\Modules\Staffing\Enums\ChangeRequestKind')
@use('App\Modules\Staffing\Enums\ChangeRequestStatus')
@php
    $tz = config('app.timezone');
    $blocked = collect($load['groups'])
        ->map(fn (array $group) => $mine['groups'][$group['id']]['load'] ?? null)
        ->reject(fn ($status) => \App\Modules\Staffing\Rules\ChangeRequestRules::acceptsRequests($status))
        ->map(fn ($status) => $status?->label() ?? 'sin abrir')
        ->unique()
        ->values();
    $requestGrid = '@[44rem]:grid-cols-[minmax(0,1.15fr)_minmax(0,1.5fr)_7.5rem_minmax(0,1.4fr)_6.5rem]';
@endphp
<section class="space-y-3" aria-labelledby="my-requests-title" data-testid="my-load-requests-slot">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="my-requests-title" class="flex items-baseline gap-2.5 text-lg font-bold">
            Mis solicitudes <span class="font-mono text-base font-semibold text-neutral">{{ count($mine['requests']) }}</span>
        </h2>
        @if ($canRequest)
            @if ($requestBlocked !== null)
                <span class="tooltip tooltip-left max-md:w-full" data-tip="{{ $requestBlocked }}">
                    <button type="button" class="btn btn-outline h-10 max-md:h-11 max-md:w-full" data-testid="request-change" disabled aria-describedby="request-blocked-reason">
                        <x-icon name="o-chat-bubble-left-right" class="h-4 w-4" aria-hidden="true" />Solicitar cambio
                    </button>
                </span>
                <span id="request-blocked-reason" class="sr-only">{{ $requestBlocked }}</span>
            @else
                <x-button label="Solicitar cambio" icon="o-chat-bubble-left-right" class="btn-outline h-10 max-md:h-11 max-md:w-full"
                    wire:click="openRequest" spinner="openRequest" data-testid="request-change" />
            @endif
        @endif
    </div>

    @if ($canRequest)
        @foreach ($blocked as $label)
            <p class="flex items-start gap-2 text-sm text-neutral" data-testid="requests-closed">
                <x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                La carga está en {{ $label }}: los cambios se piden en Asignación o Ajustes.
            </p>
        @endforeach
    @endif

    <div class="@container overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="my-requests">
        @if ($mine['requests'] === [])
            <p class="px-5 py-6 text-center text-neutral">Todavía no has pedido cambios en este periodo.</p>
        @else
            <div role="table" aria-label="Mis solicitudes">
                <div role="rowgroup">
                    <div role="row" @class(['hidden gap-x-4 border-b border-base-300 bg-secondary/40 px-4 py-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral @[44rem]:grid', $requestGrid])>
                        <span role="columnheader">Grupo</span>
                        <span role="columnheader">Cambio que pediste</span>
                        <span role="columnheader">Estado</span>
                        <span role="columnheader">Respuesta del jefe</span>
                        <span role="columnheader">Enviada</span>
                    </div>
                </div>
                <div role="rowgroup" class="divide-y divide-base-300">
                    @foreach ($mine['requests'] as $request)
                        <div role="row" wire:key="my-request-{{ $request['id'] }}" data-testid="my-request-{{ $request['id'] }}"
                            @class(['grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-4 gap-y-1.5 px-4 py-3', $requestGrid])>
                            <div role="cell" class="min-w-0">
                                <p class="line-clamp-2 font-semibold leading-snug">{{ $request['subject_name'] }}</p>
                                <p class="text-sm text-neutral"><span class="font-mono font-semibold text-base-content">{{ $request['group_code'] }}</span> · {{ $request['campus_code'] }}</p>
                            </div>
                            <div role="cell" class="col-span-2 min-w-0 text-sm @[44rem]:col-span-1">
                                <span class="flex items-center gap-1 text-xs font-medium text-neutral">
                                    <x-icon :name="$request['kind'] === ChangeRequestKind::Schedule ? 'o-calendar-days' : 'o-map-pin'" class="h-3.5 w-3.5" aria-hidden="true" />{{ $request['kind']->label() }}
                                </span>
                                <span class="block break-words">{{ $request['proposal'] }}</span>
                            </div>
                            <div role="cell" class="col-start-2 row-start-1 @[44rem]:col-start-3">
                                <x-core::status-badge :tone="$request['status']->tone()" :label="$request['status']->label()" :icon="$request['status']->icon()" />
                            </div>
                            <div role="cell" class="col-span-2 min-w-0 text-sm @[44rem]:col-span-1 @[44rem]:row-start-1 @[44rem]:col-start-4">
                                @if ($request['comment'])
                                    <p class="break-words @max-[44rem]:rounded-field @max-[44rem]:bg-base-200 @max-[44rem]:px-3 @max-[44rem]:py-2"><span class="sr-only">Respuesta del jefe: </span>{{ $request['comment'] }}</p>
                                @else
                                    <span class="text-neutral @max-[44rem]:hidden">{{ $request['status'] === ChangeRequestStatus::Pending ? 'Sin respuesta todavía' : '—' }}</span>
                                @endif
                            </div>
                            <div role="cell" class="col-span-2 text-sm text-neutral tabular-nums @[44rem]:col-span-1 @[44rem]:row-start-1 @[44rem]:col-start-5">
                                <span class="@[44rem]:sr-only">Enviada el </span>{{ $request['requested_at']->timezone($tz)->translatedFormat('j M Y') }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>
