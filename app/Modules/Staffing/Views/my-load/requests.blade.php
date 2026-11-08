{{--
    «Mis solicitudes» de Mi carga (tarea 3.11, maqueta C): sus grupos con «Solicitar cambio» (solo con el permiso de
    pedir; el botón aparece con la carga del departamento en Asignación o Ajustes y queda deshabilitado con la insignia
    de su estado si el grupo ya tiene una solicitud abierta) y, debajo, sus solicitudes del periodo: grupo, tipo, lo que
    propuso, estado, el comentario del jefe y la fecha. Con la caja de menos de 44rem, cada solicitud se apila.
--}}
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
    <div>
        <h2 id="my-requests-title" class="flex items-baseline gap-2.5 text-lg font-bold">
            Mis solicitudes <span class="font-mono text-base font-semibold text-neutral">{{ count($mine['requests']) }}</span>
        </h2>
        @if ($canRequest)
            <p class="mt-1 max-w-[70ch] text-sm text-neutral">Pide un cambio de horario o de salón en uno de tus grupos; tu jefe de departamento lo revisa. Puedes tener una solicitud abierta por grupo.</p>
        @endif
    </div>

    @if ($canRequest && $load['groups'] !== [])
        <div class="@container overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="request-groups">
            <h3 class="border-b border-base-300 bg-secondary/40 px-4 py-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Pedir un cambio</h3>
            @foreach ($blocked as $label)
                <p class="flex items-start gap-2 border-b border-base-300 px-4 py-2.5 text-sm" data-testid="requests-closed">
                    <x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0 text-neutral" />
                    La carga está en {{ $label }}: los cambios se piden en Asignación o Ajustes.
                </p>
            @endforeach
            <ul class="divide-y divide-base-300">
                @foreach ($load['groups'] as $group)
                    @php
                        $state = $mine['groups'][$group['id']] ?? ['open' => null, 'load' => null];
                        $accepts = \App\Modules\Staffing\Rules\ChangeRequestRules::acceptsRequests($state['load']);
                    @endphp
                    <li wire:key="request-group-{{ $group['id'] }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 @[40rem]:flex-nowrap">
                        <div class="flex min-w-0 flex-1 basis-64 items-baseline gap-3">
                            <span class="w-10 shrink-0 font-mono text-[15px] font-semibold">{{ $group['code'] }}</span>
                            <span class="min-w-0">
                                <span class="block truncate font-medium" title="{{ $group['subject_name'] }}">{{ $group['subject_name'] }}</span>
                                <span class="flex flex-wrap items-center gap-x-2 text-sm text-neutral">
                                    @foreach ($group['schedule'] as $line)
                                        <span class="whitespace-nowrap">{{ $line['days'] }} <span class="font-mono">{{ $line['time'] }}</span></span>
                                    @endforeach
                                    <span class="badge badge-neutral badge-soft badge-sm font-mono">{{ $group['campus_code'] }}</span>
                                </span>
                            </span>
                        </div>
                        <div class="flex shrink-0 items-center gap-2 max-[30rem]:w-full max-[30rem]:justify-between">
                            @if ($state['open'] !== null)
                                <x-core::status-badge :tone="$state['open']->tone()" :label="$state['open']->label()" data-testid="request-open-{{ $group['id'] }}" />
                            @endif
                            @if (! $accepts)
                                <span class="text-sm text-neutral">Carga en {{ $state['load']?->label() ?? 'sin abrir' }}</span>
                            @elseif ($state['open'] !== null)
                                <span class="tooltip tooltip-left" data-tip="Ya tienes una solicitud abierta para este grupo.">
                                    <button type="button" class="btn btn-sm btn-outline max-md:h-11 max-md:min-h-11" data-testid="request-change-{{ $group['id'] }}" disabled aria-describedby="request-open-reason-{{ $group['id'] }}">
                                        <x-icon name="o-chat-bubble-left-right" class="h-4 w-4" />Solicitar cambio
                                    </button>
                                </span>
                                <span id="request-open-reason-{{ $group['id'] }}" class="sr-only">Ya tienes una solicitud abierta para este grupo.</span>
                            @else
                                <x-button label="Solicitar cambio" icon="o-chat-bubble-left-right" class="btn-sm btn-outline max-md:h-11 max-md:min-h-11"
                                    wire:click="openRequest('{{ $group['id'] }}')" spinner="openRequest('{{ $group['id'] }}')" data-testid="request-change-{{ $group['id'] }}" />
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
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
                                <span class="mr-1.5 inline-flex items-center gap-1 font-medium">
                                    <x-icon :name="$request['kind'] === \App\Modules\Staffing\Enums\ChangeRequestKind::Schedule ? 'o-clock' : 'o-map-pin'" class="h-4 w-4 text-neutral" />{{ $request['kind']->label() }}
                                </span>
                                <span class="break-words">{{ $request['proposal'] }}</span>
                            </div>
                            <div role="cell" class="col-start-2 row-start-1 @[44rem]:col-start-3">
                                <x-core::status-badge :tone="$request['status']->tone()" :label="$request['status']->label()" />
                            </div>
                            <div role="cell" class="col-span-2 min-w-0 text-sm @[44rem]:col-span-1 @[44rem]:row-start-1 @[44rem]:col-start-4">
                                @if ($request['comment'])
                                    <p class="break-words @max-[44rem]:rounded-field @max-[44rem]:bg-base-200 @max-[44rem]:px-3 @max-[44rem]:py-2"><span class="sr-only">Respuesta del jefe: </span>{{ $request['comment'] }}</p>
                                @else
                                    <span class="text-neutral @max-[44rem]:hidden">{{ $request['status'] === \App\Modules\Staffing\Enums\ChangeRequestStatus::Pending ? 'Sin respuesta todavía' : '—' }}</span>
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
