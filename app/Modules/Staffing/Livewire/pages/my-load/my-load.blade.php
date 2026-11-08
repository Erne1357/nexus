{{--
    Mi carga (tarea 3.10), maqueta C «Columnas por día»: la ecuación de horas (base − externas − asignadas = restantes),
    las columnas de lunes a viernes (el sábado solo con sesiones) con un bloque por sesión (materia, grupo, salón,
    campus y, si el grupo tiene una solicitud abierta, su estado), el hueco entre dos sesiones y el traslado cuando el
    campus cambia, y al final «Mis solicitudes» con el modal «Solicitar cambio» (tarea 3.11). Todo viene de MyLoadQuery
    y MyChangeRequestsQuery.
--}}
<div class="space-y-4">
    <div>
        <h1 class="text-2xl font-bold leading-tight md:text-3xl">Mi carga</h1>
        @if ($term)
            <p class="mt-1.5 text-sm text-neutral">Tus grupos de {{ $term->name }}, día por día.</p>
        @endif
    </div>

    @if ($teacher === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="my-load-empty">
            Tu usuario no está ligado a una ficha de docente activa. Pide a tu jefe de departamento que la vincule para ver tu carga.
        </div>
    @elseif ($load === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="my-load-empty">No hay un periodo activo.</div>
    @else
        @php $h = $load['hours']; @endphp
        <div class="flex flex-wrap items-stretch overflow-hidden rounded-box border border-base-300 bg-base-100" role="group" aria-label="Tus horas" data-testid="my-load-hours">
            <div class="min-w-36 flex-1 px-4 py-3">
                <span class="text-xs font-medium uppercase tracking-wide text-neutral">{{ $h['tolerance'] > 0 ? 'Límite' : 'Horas base' }}</span>
                <b class="block font-mono text-3xl leading-tight" data-testid="hours-base">{{ $h['tolerance'] > 0 ? $h['limit'] : $h['base'] }}<small class="text-sm font-normal text-neutral"> h</small></b>
                @if ($h['tolerance'] > 0)
                    <span class="text-sm text-neutral">base {{ $h['base'] }} + {{ $h['tolerance'] }} de tolerancia</span>
                @endif
            </div>
            <div class="flex items-center px-0.5 font-mono text-2xl text-neutral" aria-hidden="true">−</div>
            <div class="min-w-36 flex-1 px-4 py-3">
                <span class="text-xs font-medium uppercase tracking-wide text-neutral">Externas</span>
                <b class="block font-mono text-3xl leading-tight" data-testid="hours-external">{{ $h['external'] }}<small class="text-sm font-normal text-neutral"> h</small></b>
                <span class="flex flex-wrap gap-x-3 text-sm text-neutral">
                    @forelse ($h['sources'] as $source)
                        <span>{{ $source['source'] }} <span class="font-mono">{{ $source['hours'] }} h</span></span>
                    @empty
                        <span>Sin horas fuera del ITCJ</span>
                    @endforelse
                </span>
            </div>
            <div class="flex items-center px-0.5 font-mono text-2xl text-neutral" aria-hidden="true">−</div>
            <div class="min-w-36 flex-1 px-4 py-3">
                <span class="text-xs font-medium uppercase tracking-wide text-neutral">Asignadas</span>
                <b class="block font-mono text-3xl leading-tight" data-testid="hours-assigned">{{ $h['assigned'] }}<small class="text-sm font-normal text-neutral"> h</small></b>
                <span class="text-sm text-neutral">{{ count($load['groups']) }} {{ count($load['groups']) === 1 ? 'grupo' : 'grupos' }}</span>
            </div>
            <div class="flex items-center px-0.5 font-mono text-2xl text-neutral" aria-hidden="true">=</div>
            <div @class(['min-w-36 flex-1 px-4 py-3', 'bg-success/10 text-success' => $h['remaining'] >= 0, 'bg-error/10 text-error' => $h['remaining'] < 0])>
                <span class="text-xs font-medium uppercase tracking-wide">Restantes</span>
                <b class="block font-mono text-3xl leading-tight" data-testid="hours-remaining">{{ $h['remaining'] }}<small class="text-sm font-normal"> h</small></b>
                @if ($h['remaining'] < 0)
                    <span class="text-sm">Pasas del límite</span>
                @endif
            </div>
        </div>

        @if ($load['groups'] === [])
            <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="my-load-no-groups">Todavía no tienes grupos asignados en este periodo.</div>
        @else
            <div @class(['grid overflow-hidden rounded-box border border-base-300 bg-base-100', 'md:grid-cols-5' => count($load['days']) === 5, 'md:grid-cols-6' => count($load['days']) === 6]) data-testid="my-load-days">
                @foreach ($load['days'] as $number => $day)
                    <section class="flex min-w-0 flex-col border-t border-base-300 first:border-t-0 md:border-l md:border-t-0 md:first:border-l-0" aria-label="{{ $day['name'] }}" data-testid="day-{{ $number }}">
                        <h2 class="flex items-baseline justify-between bg-secondary/40 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-neutral">
                            {{ $day['name'] }}
                            <small class="font-mono normal-case tracking-normal">{{ $day['hours'] > 0 ? $day['hours'].' h' : '—' }}</small>
                        </h2>
                        <div class="flex flex-1 flex-col gap-1.5 p-2">
                            @forelse ($day['blocks'] as $block)
                                @if ($block['transfer_from'])
                                    <p class="flex items-center gap-1.5 rounded-field border border-dashed border-warning/60 px-2 py-1 text-xs font-medium text-warning" data-testid="transfer">
                                        <x-icon name="o-arrows-right-left" class="h-3.5 w-3.5" aria-hidden="true" />
                                        Traslado {{ $block['transfer_from'] }} → {{ $block['campus_code'] }}
                                    </p>
                                @endif
                                @if ($block['gap'])
                                    <p class="px-1 text-xs text-neutral" data-testid="gap">Hueco de {{ $this::span($block['gap']) }}</p>
                                @endif
                                <div @class(['flex min-w-0 flex-col gap-0.5 rounded-field border-l-4 px-2.5 py-2', 'border-l-primary bg-primary/10' => $block['campus_code'] === $load['home'], 'border-l-warning bg-warning/10' => $block['campus_code'] !== $load['home']]) data-testid="block-{{ $block['group_id'] }}">
                                    <b class="line-clamp-2 text-[13px] leading-tight">{{ $block['subject_name'] }}</b>
                                    <span class="font-mono text-xs text-neutral">{{ $block['time'] }}</span>
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-xs font-semibold">
                                        {{ $block['group_code'] }}
                                        <span>{{ $block['room'] ?? 'Sin salón' }}</span>
                                        <span class="badge badge-neutral badge-soft badge-sm" title="{{ $block['campus_name'] }}">{{ $block['campus_code'] }}</span>
                                    </span>
                                    @if ($open = $mine['groups'][$block['group_id']]['open'] ?? null)
                                        <span class="mt-0.5"><x-core::status-badge :tone="$open->tone()" :label="'Solicitud '.mb_strtolower($open->label())" /></span>
                                    @endif
                                </div>
                            @empty
                                <p class="px-1 py-2 text-sm text-neutral">Sin clases</p>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        @endif

        @include('staffing::my-load.requests')

        <x-modal wire:model="showRequest" box-class="max-w-xl" aria-labelledby="request-title" data-testid="request-modal">
            @if ($showRequest && $requestGroup !== null)
                @include('staffing::my-load.request-form', ['group' => $requestGroup])
            @endif
        </x-modal>
    @endif
</div>
