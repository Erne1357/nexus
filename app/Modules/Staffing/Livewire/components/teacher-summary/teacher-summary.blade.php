{{--
    El tablero «Resumen» del docente (maqueta C «Tablero como el del alumno», DESIGN.md «Resumen del docente»). Cada caja sale
    solo cuando aplica: «Hoy» cambia entre semestres, «Atención» solo trae lo que aplica, «Tu semana» es la del periodo en
    curso o, entre semestres, la del periodo en planeación. Todo sale de TeacherSummaryQuery: ninguna consulta aquí.
--}}
@php
    $label = 'text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral';
    $tones = ['info' => 'text-info', 'warning' => 'text-warning'];
@endphp
<div class="space-y-4" data-testid="teacher-summary">
    @if ($summary === null)
        <x-alert title="Todavía no encontramos tu ficha de docente." description="Pídele a tu jefe de departamento que la revise." icon="o-information-circle" class="alert-info" data-testid="summary-empty" />
        <section class="space-y-3 rounded-box border border-base-300 bg-base-100 p-4 md:p-5" aria-labelledby="summary-access-title" data-testid="summary-access">
            <h2 id="summary-access-title" class="text-base font-bold">Tu acceso</h2>
            <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-[auto_1fr]">
                <dt class="text-neutral">Usuario</dt>
                <dd class="font-mono font-medium">{{ $user->username }}</dd>
                <dt class="text-neutral">Correo institucional</dt>
                <dd class="font-medium [overflow-wrap:anywhere]">{{ $user->email ?? 'Sin correo institucional' }}</dd>
            </dl>
            <x-button label="Cambiar contraseña" icon="o-key" :link="route('user-password.edit')" class="btn-outline" no-wire-navigate data-testid="summary-change-password" />
        </section>
    @else
        @php
            $identity = $summary->identity;
            $tiles = $summary->tiles;
            $today = $summary->today;
            $planning = $summary->planning;
            $week = $summary->week;
            $availability = $summary->availability;
            $experience = $summary->experience;
            $planTile = $tiles['planning'];
            $tileCount = 1 + ($tiles['in_progress'] !== null ? 1 : 0) + ($planTile !== null ? 2 : 0);
            $plazas = collect($identity['positions']);
            $initials = collect(preg_split('/\s+/', trim($identity['name'])))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
        @endphp

        {{-- Ficha --}}
        <section class="flex items-start gap-4 rounded-box border border-base-300 bg-base-100 p-4 md:items-center md:p-5" aria-label="Tus datos" data-testid="summary-identity">
            <span class="flex aspect-3/4 w-[4.5rem] shrink-0 items-center justify-center rounded-md border border-base-300 bg-base-200 text-2xl font-bold text-neutral md:w-24" aria-hidden="true">{{ $initials }}</span>
            <div class="min-w-0 flex-1">
                <h2 class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xl font-bold leading-tight md:text-2xl">
                    {{ $identity['name'] }}
                    <x-core::status-badge :tone="$identity['active'] ? 'success' : 'neutral'" :label="$identity['active'] ? 'Activo' : 'Inactivo'" />
                </h2>
                <dl class="mt-3 grid grid-cols-2 gap-x-5 gap-y-3 desktop:grid-cols-[2fr_1.4fr_1fr_1fr]">
                    <div class="col-span-2 desktop:col-span-1">
                        <dt class="{{ $label }}">Departamento</dt>
                        <dd class="font-medium" data-testid="identity-department">
                            @if ($identity['department'] !== null)
                                <span class="font-mono">{{ $identity['department']['code'] }}</span> · {{ $identity['department']['name'] }}@if ($identity['other_departments'] > 0) <span class="text-sm font-normal text-neutral">y {{ $identity['other_departments'] }} más</span>@endif
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="{{ $label }}">Plaza</dt>
                        <dd class="font-medium" data-testid="identity-position">
                            @if ($plazas->isEmpty())
                                —
                            @elseif ($plazas->count() === 1)
                                {{ $plazas->first()['kind'] }} · <span class="font-mono">{{ $plazas->first()['base_hours'] }}</span> h
                            @else
                                <span class="font-mono">{{ $plazas->sum('base_hours') }}</span> h · <span class="font-mono">{{ $plazas->count() }}</span> plazas
                            @endif
                        </dd>
                    </div>
                    <div><dt class="{{ $label }}">Grado</dt><dd class="font-medium">{{ $identity['degree'] ?? '—' }}</dd></div>
                    <div><dt class="{{ $label }}">Ingreso</dt><dd class="font-mono font-medium">{{ $identity['hired_on']?->format('d/m/Y') ?? '—' }}</dd></div>
                </dl>
            </div>
        </section>

        {{-- Cuatro cifras --}}
        <section @class(['grid grid-cols-2 overflow-hidden rounded-box border border-base-300 bg-base-100', 'desktop:grid-cols-4' => $tileCount === 4, 'desktop:grid-cols-3' => $tileCount === 3, 'desktop:grid-cols-2' => $tileCount === 2, 'desktop:grid-cols-1' => $tileCount === 1]) aria-label="Tus cifras" data-testid="summary-tiles">
            <div class="flex flex-col gap-1.5 px-4 py-3.5 md:px-5">
                <span class="{{ $label }}">Hoy</span>
                <span class="font-mono text-[26px] font-semibold leading-none tabular-nums" data-testid="tile-today">{{ $today['state'] === 'between_terms' ? '—' : $tiles['today_count'] }}@if ($today['state'] !== 'between_terms') <span class="font-sans text-base font-normal text-neutral">{{ $tiles['today_count'] === 1 ? 'clase' : 'clases' }}</span>@endif</span>
                <span class="text-sm text-neutral" data-testid="tile-today-caption">{{ $tiles['today_caption'] }}</span>
            </div>
            @if ($tiles['in_progress'] !== null)
                <div class="flex flex-col gap-1.5 border-base-300 px-4 py-3.5 md:px-5 [&:nth-child(n+2)]:border-l">
                    <span class="{{ $label }}">{{ $tiles['in_progress']['term_name'] }}</span>
                    <span class="font-mono text-[26px] font-semibold leading-none tabular-nums" data-testid="tile-in-progress">{{ $tiles['in_progress']['hours'] }} <span class="font-sans text-base font-normal text-neutral">h</span></span>
                    <span class="text-sm text-neutral"><span class="font-mono">{{ $tiles['in_progress']['groups'] }}</span> {{ $tiles['in_progress']['groups'] === 1 ? 'grupo' : 'grupos' }}</span>
                </div>
            @endif
            @if ($planTile !== null)
                @php
                    $scale = max($planTile['limit'], $planTile['assigned'], 1);
                    $over = $planTile['over_band'] && ! $planTile['accepted'];
                @endphp
                <div class="flex flex-col gap-1.5 border-base-300 px-4 py-3.5 max-desktop:border-t md:px-5 desktop:border-l [&:nth-child(n+2)]:border-l">
                    <span class="{{ $label }}">{{ $planTile['term_name'] }}</span>
                    <span @class(['font-mono text-[26px] font-semibold leading-none tabular-nums', 'text-warning' => $over]) data-testid="tile-planning">{{ $planTile['assigned'] }} <span class="font-sans text-base font-normal text-neutral">h</span></span>
                    <div class="space-y-1" aria-hidden="true">
                        <progress @class(['progress h-1.5 w-full', 'progress-warning' => $over, 'progress-success' => ! $over]) value="{{ $planTile['assigned'] }}" max="{{ $scale }}"></progress>
                        @if (! $planTile['no_plaza'] && $planTile['band_max'] > 0)
                            <div class="relative h-1 rounded-full bg-base-200"><span class="absolute inset-y-0 rounded-full bg-base-content/40" style="left: {{ round($planTile['band_min'] / $scale * 100, 1) }}%; width: {{ round(max($planTile['band_max'] - $planTile['band_min'], 0.5) / $scale * 100, 1) }}%"></span></div>
                        @endif
                    </div>
                    <span @class(['flex items-start gap-1 text-sm', 'text-warning' => $over, 'text-neutral' => ! $over])>@if ($over)<x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />@endif{{ $planTile['band_text'] ?? $planTile['limit_label'] }}</span>
                </div>
                <div @class(['flex flex-col gap-1.5 border-base-300 border-l px-4 py-3.5 max-desktop:border-t md:px-5', 'max-desktop:border-l-0' => $tileCount === 3, 'text-warning' => $tiles['remaining']['over']])>
                    <span @class([$label, 'text-warning!' => $tiles['remaining']['over']])>Restantes</span>
                    <span class="font-mono text-[26px] font-semibold leading-none tabular-nums" data-testid="tile-remaining">{{ $tiles['remaining']['hours'] }} <span class="font-sans text-base font-normal @if (! $tiles['remaining']['over']) text-neutral @endif">h</span></span>
                    <span @class(['text-sm', 'text-neutral' => ! $tiles['remaining']['over']])>{{ $tiles['remaining']['caption'] }}</span>
                </div>
            @endif
        </section>

        {{-- Hoy | Atención --}}
        <div class="grid items-start gap-4 desktop:grid-cols-2">
            <section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-today-title" data-testid="summary-today">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-base-300 px-4 py-3 md:px-5">
                    <h2 id="summary-today-title" class="text-base font-bold">Hoy</h2>
                    <span class="text-sm text-neutral">{{ $today['date_label'] }}</span>
                    <a href="{{ $planning['my_load_url'] ?? route('staffing.my-load') }}" wire:navigate class="ml-auto inline-flex items-center gap-1 font-medium text-primary">Ver Mi carga <x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
                </div>
                @if ($today['state'] === 'between_terms')
                    <p class="px-4 py-3 text-neutral md:px-5" data-testid="today-between">
                        @if ($today['ended'] !== null){{ $today['ended']['term'] }} terminó el {{ $today['ended']['ended_on']->locale('es')->isoFormat('D [de] MMMM') }}@endif@if ($today['ended'] !== null && $today['upcoming'] !== null); @endif@if ($today['upcoming'] !== null){{ $today['upcoming']['term'] }} empieza el {{ $today['upcoming']['starts_on']->locale('es')->isoFormat('D [de] MMMM') }}@endif.
                    </p>
                @elseif ($today['state'] === 'none')
                    <p class="px-4 py-3 md:px-5" data-testid="today-none"><b>Hoy no tienes clases.</b>@if ($today['next'] !== null) <span class="text-neutral">{{ $today['next']['day_label'] }} a las <span class="font-mono">{{ $today['next']['time'] }}</span>, {{ $today['next']['subject'] }}.</span>@endif</p>
                @else
                    <ol data-testid="today-items">
                        @foreach ($today['items'] as $item)
                            @if ($item['transfer'] !== null)
                                <li @class(['flex items-center gap-1.5 border-b border-dashed px-4 py-1.5 text-xs font-medium md:px-5', 'border-warning/60 bg-warning/10 text-warning' => $item['transfer']['short'], 'border-base-300 bg-base-200 text-neutral' => ! $item['transfer']['short']]) data-testid="today-transfer">
                                    <x-icon :name="$item['transfer']['short'] ? 'o-exclamation-triangle' : 'o-arrows-right-left'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Traslado {{ $item['transfer']['from'] }} → {{ $item['campus'] }}
                                </li>
                            @elseif ($item['gap_label'] !== null)
                                <li class="border-b border-base-300 bg-base-200 px-4 py-1.5 text-xs text-neutral md:px-5">{{ $item['gap_label'] }}</li>
                            @endif
                            <li @class(['grid grid-cols-[auto_1fr] items-start gap-x-4 gap-y-1.5 border-b border-base-300 px-4 py-3 last:border-b-0 md:px-5', 'bg-info/10' => in_array($item['status'], ['next', 'now'], true), 'text-neutral' => $item['status'] === 'done']) data-testid="today-item" data-status="{{ $item['status'] }}">
                                <span class="w-[6.5rem] whitespace-nowrap font-mono text-sm font-semibold tabular-nums">{{ $item['time'] }}</span>
                                <div class="flex min-w-0 flex-wrap items-start justify-between gap-x-3 gap-y-1.5">
                                    <div class="min-w-0">
                                        <div class="font-medium">{{ $item['subject'] }}</div>
                                        <div class="text-sm text-neutral"><span class="font-mono">{{ $item['code'] }}</span> · Grupo <span class="font-mono">{{ $item['group'] }}</span> · @if ($item['room'] !== null)Salón <span class="font-mono">{{ $item['room'] }}</span>@else<span class="inline-flex items-center gap-1 text-warning"><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5" aria-hidden="true" />Sin salón</span>@endif · {{ $item['campus'] }}</div>
                                    </div>
                                    @if ($item['status'] === 'done')
                                        <x-core::status-badge tone="neutral" label="Terminó" icon="o-check-circle" />
                                    @elseif ($item['status'] === 'now')
                                        <x-core::status-badge tone="info" label="Ahora" icon="o-play-circle" />
                                    @elseif ($item['status'] === 'next')
                                        <x-core::status-badge tone="info" :label="'Siguiente · '.$item['starts_in']" icon="o-clock" />
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    <div class="border-t border-base-300 px-4 py-3 text-sm text-neutral md:px-5"><span class="font-mono">{{ $tiles['today_count'] }}</span> {{ $tiles['today_count'] === 1 ? 'clase hoy' : 'clases hoy' }}</div>
                @endif
            </section>

            <section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-attention-title" data-testid="summary-attention">
                <div class="flex items-center justify-between gap-3 border-b border-base-300 px-4 py-3 md:px-5">
                    <h2 id="summary-attention-title" class="text-base font-bold">Atención</h2>
                </div>
                @forelse ($summary->attention as $item)
                    <div class="flex items-start gap-3 border-b border-base-300 px-4 py-3 last:border-b-0 md:px-5" data-testid="attention-row" data-kind="{{ $item['kind'] }}">
                        <x-icon :name="$item['icon']" class="mt-0.5 h-5 w-5 shrink-0 {{ $tones[$item['tone']] ?? 'text-info' }}" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <b>{{ $item['title'] }}</b>
                            <p class="text-sm text-neutral">{{ $item['detail'] }}</p>
                            @if ($item['url'] !== null)
                                <a href="{{ $item['url'] }}" wire:navigate class="mt-1 inline-flex items-center gap-1 text-sm font-medium text-primary">{{ $item['kind'] === 'selection_turn' ? 'Ir a la oferta' : 'Ver Mi carga' }} <x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="flex items-start gap-3 px-4 py-4 md:px-5" data-testid="attention-empty">
                        <x-icon name="o-check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-success" aria-hidden="true" />
                        <p><b>Nada que atender.</b> <span class="text-neutral">Por ahora no hay nada pendiente.</span></p>
                    </div>
                @endforelse
            </section>
        </div>

        {{-- Tu semana: columnas desde 56rem de su caja; antes, un renglón por día. --}}
        @if ($week !== null)
            <section class="@container rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-week-title" data-testid="summary-week">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-base-300 px-4 py-3 md:px-5">
                    <h2 id="summary-week-title" class="text-base font-bold">{{ $week['title'] }}</h2>
                    <span class="text-sm text-neutral">{{ $week['term_name'] }}</span>
                    @if ($week['provisional'])<span class="text-sm text-neutral">· puede cambiar hasta que se publique</span>@endif
                </div>
                <div @class(['grid', '@[56rem]:grid-cols-5' => count($week['days']) === 5, '@[56rem]:grid-cols-6' => count($week['days']) === 6])>
                    @foreach ($week['days'] as $day)
                        <div @class(['flex min-w-0 flex-col border-t border-base-300 first:border-t-0 @[56rem]:border-l @[56rem]:border-t-0 @[56rem]:first:border-l-0', 'bg-base-200' => $day['today']]) data-testid="week-day" @if ($day['today']) data-today="true" @endif>
                            <h3 class="flex items-baseline justify-between px-3 pt-2.5 text-[11.5px] font-semibold uppercase tracking-[0.09em] text-neutral">
                                {{ $day['name'] }}
                                <small class="font-mono text-xs normal-case tracking-normal">{{ $day['today'] ? 'Hoy · ' : '' }}{{ $day['hours'] > 0 ? $day['hours'].' h' : '—' }}</small>
                            </h3>
                            <div class="flex flex-1 flex-col gap-1.5 p-2">
                                @foreach ($day['blocks'] as $block)
                                    <div class="flex min-w-0 flex-col gap-0.5 rounded-field border border-base-300 bg-base-100 px-2.5 py-2">
                                        <span class="font-mono text-xs font-semibold tabular-nums">{{ $block['time'] }}</span>
                                        <b class="line-clamp-2 text-sm leading-tight">{{ $block['subject'] }}</b>
                                        <span class="text-xs text-neutral"><span class="font-mono">{{ $block['group'] }}</span> · @if ($block['room'] !== null)<span class="font-mono">{{ $block['room'] }}</span>@else<span class="text-warning">Sin salón</span>@endif</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-base-300 px-4 py-3 text-sm text-neutral md:px-5">{{ $week['footer'] }}</div>
            </section>
        @endif

        <div class="grid items-start gap-4 desktop:grid-cols-2">
            {{-- Periodo en planeación --}}
            @if ($planning !== null)
                @php $h = $planning['hours']; @endphp
                <section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-planning-title" data-testid="summary-planning">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-base-300 px-4 py-3 md:px-5">
                        <h2 id="summary-planning-title" class="text-base font-bold">{{ $planning['term_name'] }}</h2>
                        @if ($planning['badge'] === 'selection')
                            <x-core::status-badge tone="info" label="Selección en curso" icon="o-clock" />
                        @elseif ($planning['badge'] === 'published')
                            <x-core::status-badge tone="success" label="Publicada" icon="o-check-circle" />
                        @else
                            <x-core::status-badge tone="info" label="En preparación" icon="o-clock" />
                        @endif
                        <a href="{{ $planning['my_load_url'] }}" wire:navigate class="ml-auto inline-flex items-center gap-1 font-medium text-primary">Ver tu carga <x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
                    </div>
                    <div class="space-y-3 px-4 py-3 md:px-5">
                        @if ($planning['published'] !== [])
                            <p class="flex flex-wrap gap-x-4 text-sm text-neutral" data-testid="planning-published">@include('staffing::shared.published', ['stamps' => $planning['published']])</p>
                        @endif
                        @if ($planning['badge'] !== 'published')
                            <p class="text-neutral">Tu jefe de departamento está armando la carga; puede cambiar hasta que se publique.</p>
                        @endif
                        <div class="grid grid-cols-3 overflow-hidden rounded-box border border-base-300" role="group" aria-label="Tus horas de {{ $planning['term_name'] }}" data-testid="planning-hours">
                            <div class="min-w-0 px-3 py-2.5">
                                <span class="{{ $label }}">{{ $planTile['limit_label'] ?? '' }}</span>
                                <b class="block font-mono text-[26px] leading-tight">{{ $h['limit'] }}<small class="font-sans text-sm font-normal text-neutral"> h</small></b>
                                <span class="text-sm text-neutral">{{ $planTile['band_text'] ?? '' }}</span>
                            </div>
                            <div class="min-w-0 border-l border-base-300 px-3 py-2.5">
                                <span class="{{ $label }}">Asignadas</span>
                                <b class="block font-mono text-[26px] leading-tight">{{ $h['assigned'] }}<small class="font-sans text-sm font-normal text-neutral"> h</small></b>
                                <span class="text-sm text-neutral"><span class="font-mono">{{ count($planning['groups']) }}</span> {{ count($planning['groups']) === 1 ? 'grupo' : 'grupos' }}@if ($h['external'] > 0) · <span class="font-mono">{{ $h['external'] }}</span> h de otra modalidad @endif</span>
                            </div>
                            <div @class(['min-w-0 border-l border-base-300 px-3 py-2.5', 'bg-success/10 text-success' => $h['remaining'] >= 0, 'bg-warning/10 text-warning' => $h['remaining'] < 0])>
                                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em]">Restantes</span>
                                <b class="block font-mono text-[26px] leading-tight">{{ $h['remaining'] }}<small class="font-sans text-sm font-normal"> h</small></b>
                                <span class="text-sm">{{ $tiles['remaining']['caption'] }}</span>
                            </div>
                        </div>
                    </div>
                    @forelse ($planning['groups'] as $group)
                        <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1.5 border-t border-base-300 px-4 py-3 md:px-5" data-testid="planning-group">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="w-6 shrink-0 font-mono text-sm font-semibold">{{ $group['code'] }}</span>
                                <div class="min-w-0">
                                    <div class="font-medium">{{ $group['subject'] }}</div>
                                    <div class="text-sm text-neutral"><span class="font-mono">{{ $group['subject_code'] }}</span> · @if ($group['rooms'] !== [])Salón <span class="font-mono">{{ implode(', ', $group['rooms']) }}</span>@endif @if ($group['no_room'])<span class="inline-flex items-center gap-1 text-warning"><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5" aria-hidden="true" />Sin salón</span>@endif · {{ $group['campus'] }}</div>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($group['schedule'] as $slot)
                                    <span class="rounded-field border border-base-300 bg-base-200 px-2 py-0.5 text-sm"><b>{{ $slot['days'] }}</b> <span class="font-mono">{{ $slot['time'] }}</span></span>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="border-t border-base-300 px-4 py-3 text-neutral md:px-5" data-testid="planning-empty">Aún no tienes grupos de {{ $planning['term_name'] }}.@if ($planning['badge'] === 'selection') En tu turno los tomas en la oferta.@endif</p>
                    @endforelse
                    @if ($planning['requests'] !== [])
                        <div class="space-y-1.5 border-t border-base-300 px-4 py-3 md:px-5" data-testid="planning-requests">
                            <h3 class="{{ $label }}">Tus solicitudes</h3>
                            @foreach ($planning['requests'] as $request)
                                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                    <span class="min-w-0 text-sm">Grupo <span class="font-mono font-semibold">{{ $request['group_code'] }}</span> · {{ $request['subject'] }}</span>
                                    <x-core::status-badge :tone="$request['tone']" :label="$request['status_label']" :icon="$request['icon']" />
                                </div>
                            @endforeach
                            @if ($planning['requests_more'] > 0)<p class="text-sm text-neutral">y {{ $planning['requests_more'] }} más en Mi carga.</p>@endif
                        </div>
                    @endif
                </section>
            @endif

            <div class="space-y-4 @if ($planning === null) desktop:col-span-2 @endif">
                {{-- Experiencia académica --}}
                <section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-experience-title" data-testid="summary-experience">
                    <div class="flex items-baseline justify-between gap-3 border-b border-base-300 px-4 py-3 md:px-5">
                        <h2 id="summary-experience-title" class="text-base font-bold">Tu experiencia académica</h2>
                        <span class="text-sm text-neutral"><span class="font-mono">{{ $experience['total'] }}</span> {{ $experience['total'] === 1 ? 'materia' : 'materias' }}</span>
                    </div>
                    @if ($experience['total'] === 0)
                        <p class="px-4 py-3 text-neutral md:px-5">Sin experiencia académica registrada.</p>
                    @else
                        <div class="flex flex-wrap items-center gap-1.5 px-4 py-3 md:px-5" x-data="{ open: false }">
                            @foreach ($experience['subjects'] as $subject)
                                <span class="rounded-field border border-base-300 bg-base-200 px-2.5 py-1 text-sm" title="{{ $subject['code'] }}">{{ $subject['name'] }}</span>
                            @endforeach
                            @if ($experience['more'] > 0)
                                @foreach ($experience['rest'] as $subject)
                                    <span x-cloak x-show="open" class="rounded-field border border-base-300 bg-base-200 px-2.5 py-1 text-sm" title="{{ $subject['code'] }}">{{ $subject['name'] }}</span>
                                @endforeach
                                <button type="button" class="font-medium text-primary" x-on:click="open = ! open" x-bind:aria-expanded="open" data-testid="experience-more"><span x-show="! open">y {{ $experience['more'] }} más</span><span x-cloak x-show="open">Ver menos</span></button>
                            @endif
                        </div>
                    @endif
                </section>

                {{-- Disponibilidad --}}
                @if ($availability !== null)
                    <section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-availability-title" data-testid="summary-availability">
                        <div class="flex items-baseline justify-between gap-3 border-b border-base-300 px-4 py-3 md:px-5">
                            <h2 id="summary-availability-title" class="text-base font-bold">Tu disponibilidad</h2>
                            <span class="text-sm text-neutral">{{ $availability['term_name'] }}</span>
                        </div>
                        <div class="space-y-2 px-4 py-3 md:px-5">
                            @if ($availability['all_available'])
                                <p><b>Disponible en todo el horario.</b></p>
                                <p class="text-sm text-neutral">Si algo cambió, avísale a tu jefe de departamento.</p>
                            @else
                                <p class="text-sm text-neutral"><b class="font-mono text-base-content">{{ $availability['available'] }}</b> de <span class="font-mono">{{ $availability['total'] }}</span> h de la cuadrícula disponibles.</p>
                                <dl class="grid gap-x-4 gap-y-1.5 sm:grid-cols-[auto_1fr]">
                                    @foreach ($availability['days'] as $day)
                                        <dt class="font-medium">{{ $day['name'] }}</dt>
                                        <dd class="flex flex-wrap gap-1.5">
                                            @foreach ($day['ranges'] as $range)
                                                <span class="inline-flex items-center gap-1 rounded-field border border-base-300 px-2 py-0.5 text-sm"><span class="font-mono">{{ $range['label'] }}</span> <span class="text-neutral">{{ $range['kind_label'] }}</span></span>
                                            @endforeach
                                        </dd>
                                    @endforeach
                                </dl>
                            @endif
                        </div>
                    </section>
                @endif

                {{-- Tu acceso (IN-01) --}}
                <section class="space-y-3 rounded-box border border-base-300 bg-base-100 p-4 md:p-5" aria-labelledby="summary-access-title" data-testid="summary-access">
                    <h2 id="summary-access-title" class="text-base font-bold">Tu acceso</h2>
                    <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-[auto_1fr]">
                        <dt class="text-neutral">Usuario</dt>
                        <dd class="font-mono font-medium">{{ $summary->account['username'] }}</dd>
                        <dt class="text-neutral">Correo institucional</dt>
                        <dd class="font-medium [overflow-wrap:anywhere]">{{ $summary->account['email'] ?? 'Sin correo institucional' }}</dd>
                    </dl>
                    <x-button label="Cambiar contraseña" icon="o-key" :link="route('user-password.edit')" class="btn-outline" no-wire-navigate data-testid="summary-change-password" />
                </section>
            </div>
        </div>
    @endif
</div>
