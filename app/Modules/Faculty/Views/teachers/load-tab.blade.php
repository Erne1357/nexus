{{--
    Pestaña Carga de la ficha del docente (D-03, maqueta .impeccable/mocks/seleccion/ficha.html): dos semanas, el
    periodo en curso («Del SII» o «De Nexus» según su fuente) y el siguiente cuando ya tiene grupos en Nexus («De la
    selección»), cada una con su cuadrícula (x-core::week-grid), las horas contra la base y el margen. Sin grupos del
    siguiente periodo, un estado vacío. `$weekly` sale de TeacherWeeklyLoad. Se incluye con @include desde la ficha.
--}}
@php
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
            $over = $week['hours'] > $week['base'] + $week['tolerance'];
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
                    <span class="text-neutral">h de base {{ $week['base'] }}@if ($week['tolerance'] > 0) (±{{ $week['tolerance'] }})@endif</span>
                    <p class="text-xs font-semibold {{ $over ? 'text-error' : 'text-success' }}">
                        <x-icon :name="$over ? 'o-exclamation-triangle' : 'o-check-circle'" class="inline w-3.5 h-3.5" />
                        {{ $over ? 'Rebasa su margen' : 'Dentro de su margen' }}
                    </p>
                </div>
            </header>

            <x-core::week-grid :days="$week['days']" :label="'Semana de '.$week['term']" />

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
