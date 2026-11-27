{{--
    Pestaña Carga de la ficha del docente (D-03, maqueta .impeccable/mocks/seleccion/ficha.html): dos semanas, el
    periodo en curso («Del SII» o «De Nexus» según su fuente) y el siguiente cuando ya tiene grupos en Nexus («De la
    selección»), cada una con su cuadrícula (x-core::week-grid), las horas frente a grupo con su banda (PL-01) y el aviso de HC-08. Sin grupos del
    siguiente periodo, un estado vacío. `$weekly` sale de TeacherWeeklyLoad. Se incluye con @include desde la ficha.
--}}
@php
    // Color de cada departamento (tokens chain-N de DESIGN.md); solo el punto y el borde, el texto va en base-content.
    $dots = [1 => 'bg-chain-1', 'bg-chain-2', 'bg-chain-3', 'bg-chain-4', 'bg-chain-5', 'bg-chain-6', 'bg-chain-7', 'bg-chain-8', 'bg-chain-9', 'bg-chain-10', 'bg-chain-11', 'bg-chain-12'];
    $periods = [
        'current' => ['week' => $weekly['current'], 'badge' => 'En curso'],
        'next' => ['week' => $weekly['next'], 'badge' => null],
    ];
@endphp

<div class="space-y-5" data-testid="load-tab">
    @if ($weekly['current'] === null && $weekly['next'] === null)
        <div class="rounded-box border border-dashed border-base-300 p-6 text-neutral" data-testid="load-empty">
            <p class="font-semibold text-base-content">No hay un periodo en curso ni carga del siguiente.</p>
        </div>
    @endif

    @foreach ($periods as $key => ['week' => $week, 'badge' => $badge])
        @continue ($week === null)
        @php
            $nexus = $week['source'] === 'nexus';
            $groupCount = count($week['groups']);
            $over = $week['over_band'];
        @endphp
        <section class="rounded-box border border-base-300 bg-base-100 p-4 md:p-5" aria-labelledby="h-load-{{ $key }}" data-testid="load-{{ $key }}">
            <header class="mb-3 flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <div>
                    <h2 id="h-load-{{ $key }}" class="flex flex-wrap items-center gap-2 text-lg font-bold">
                        {{ $week['term'] }}
                        @if ($key === 'current')
                            <span class="badge badge-neutral badge-soft badge-sm"><x-icon name="o-arrow-down-tray" class="w-3.5 h-3.5" />{{ $nexus ? 'De Nexus' : 'Del SII' }}</span>
                            <span class="badge badge-primary badge-sm">En curso</span>
                        @else
                            <span class="badge badge-success badge-soft badge-sm"><x-icon name="o-check-circle" class="w-3.5 h-3.5" />De la selección</span>
                        @endif
                    </h2>
                    <p class="text-sm text-neutral">
                        {{ $key === 'current' ? ($nexus ? 'Lo que imparte este semestre, como está en Nexus' : 'Lo que imparte este semestre, como está en el SII') : 'Lo que tiene en Nexus para el siguiente semestre' }}
                        · {{ $groupCount }} {{ $groupCount === 1 ? 'grupo' : 'grupos' }}
                    </p>
                </div>
                <div class="text-end" data-testid="load-hours-{{ $key }}">
                    <span class="font-mono text-2xl font-semibold tabular-nums {{ $over ? 'text-error' : 'text-success' }}">{{ $week['hours'] }}</span>
                    <span class="text-neutral">h</span>
                    <p class="text-sm text-neutral" data-testid="load-band-{{ $key }}">
                        @if ($week['honorarios_cap'] !== null)
                            Tope de honorarios: {{ $week['honorarios_cap'] }} h
                        @else
                            Frente a grupo: {{ $week['teaching'] }} h ({{ $week['band_from'] }} a {{ $week['band_to'] }})
                        @endif
                    </p>
                    <p class="text-xs font-semibold {{ $over ? 'text-error' : 'text-success' }}">
                        <x-icon :name="$over ? 'o-exclamation-triangle' : 'o-check-circle'" class="inline w-3.5 h-3.5" />
                        {{ $over ? 'Pasa su banda' : 'Dentro de su banda' }}
                    </p>
                    @if ($week['notice'])
                        <p class="mt-1 max-w-sm text-xs text-error" data-testid="load-notice-{{ $key }}">{{ $week['notice'] }}</p>
                    @endif
                </div>
            </header>

            @php
                $departments = collect($week['departments']);
                $home = $departments->where('home', true);
                $others = $departments->where('home', false);
            @endphp
            @if ($departments->isEmpty())
                <x-core::week-grid :days="$week['days']" :label="'Semana de '.$week['term']" />
            @else
                <div class="grid gap-4 xl:grid-cols-[1.1fr_1fr]" data-testid="load-departments-{{ $key }}">
                    <div class="space-y-4">
                        @foreach ([['label' => null, 'items' => $home], ['label' => 'En otros departamentos', 'items' => $others]] as $block)
                            @continue ($block['items']->isEmpty())
                            @if ($block['label'])
                                <h3 class="pt-1 text-xs font-medium uppercase tracking-widest text-neutral" data-testid="load-others-{{ $key }}">{{ $block['label'] }} · {{ $block['items']->sum('hours') }} h</h3>
                            @endif
                            @foreach ($block['items'] as $department)
                                <section class="overflow-hidden rounded-box border border-base-300" data-testid="load-department" data-department="{{ $department['name'] }}">
                                    <header class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5">
                                        <div>
                                            <span class="inline-block h-2.5 w-2.5 rounded-sm {{ $dots[$department['tone']] }}" aria-hidden="true"></span>
                                            <span class="font-bold">{{ $department['home'] ? 'En '.$department['name'] : $department['name'] }}</span>
                                            @if ($department['home'])
                                                <span class="badge badge-success badge-soft badge-sm">Su departamento</span>
                                            @else
                                                <span class="badge badge-info badge-soft badge-sm">Otro departamento</span>
                                                <p class="text-sm text-neutral">{{ $department['head'] ? 'Jefe: '.$department['head'] : 'Sin jefe vigente' }}</p>
                                            @endif
                                        </div>
                                        <span class="font-mono font-semibold tabular-nums">{{ $department['hours'] }} h · {{ count($department['groups']) }} {{ count($department['groups']) === 1 ? 'grupo' : 'grupos' }}</span>
                                    </header>
                                    @if ($department['groups'] === [])
                                        <p class="border-t border-base-300 px-3 py-2.5 text-sm text-neutral">Sin grupos en este periodo.</p>
                                    @else
                                        <ul class="divide-y divide-base-300 border-t border-base-300">
                                            @foreach ($department['groups'] as $group)
                                                <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                                                    <div><span class="font-semibold">{{ $group['subject_name'] }}</span> <span class="font-mono text-neutral">{{ $group['subject_code'] }} · {{ $group['code'] }}</span></div>
                                                    <span class="font-mono font-semibold tabular-nums">{{ $group['hours'] }} h</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </section>
                            @endforeach
                        @endforeach
                    </div>
                    <div class="space-y-2">
                        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm" aria-label="Departamentos" data-testid="load-legend-{{ $key }}">
                            @foreach ($departments as $department)
                                <li><span class="inline-block h-2.5 w-2.5 rounded-sm {{ $dots[$department['tone']] }}" aria-hidden="true"></span> {{ $department['name'] }}</li>
                            @endforeach
                        </ul>
                        <x-core::week-grid :days="$week['days']" :label="'Semana de '.$week['term']" />
                    </div>
                </div>
            @endif

            @if (! $nexus && $key === 'current')
                <p class="mt-3 border-t border-base-300 pt-3 text-xs text-neutral">Los salones y horarios vienen de la última importación del SII.</p>
            @endif
        </section>
    @endforeach

    @if ($weekly['next'] === null && $weekly['upcoming'] !== null)
        <section class="rounded-box border border-base-300 bg-base-100 p-4 md:p-5" aria-labelledby="h-load-upcoming" data-testid="load-upcoming">
            <h2 id="h-load-upcoming" class="mb-3 flex flex-wrap items-center gap-2 text-lg font-bold">
                {{ $weekly['upcoming'] }}
                <span class="badge badge-neutral badge-soft badge-sm"><x-icon name="o-calendar" class="w-3.5 h-3.5" />Siguiente periodo</span>
            </h2>
            <div class="flex gap-3 rounded-box border border-dashed border-base-300 p-4">
                <x-icon name="o-calendar-days" class="w-6 h-6 shrink-0 text-neutral" />
                <p class="text-sm text-neutral"><span class="font-semibold text-base-content">Todavía no tiene grupos de {{ $weekly['upcoming'] }}.</span> Aquí aparece su semana en cuanto tenga grupos en Nexus.</p>
            </div>
        </section>
    @endif
</div>
