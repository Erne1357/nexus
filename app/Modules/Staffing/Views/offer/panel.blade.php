{{--
    Panel de un grupo en la oferta (tarea 3.10): el grupo, el lugar del docente y SU desglose. Nunca recibe las filas de
    otros docentes: TeacherOfferQuery solo lee el ranking con su id.
--}}
@php
    [$tone, $label, $icon] = $position($group);
    $b = $group['breakdown'];
@endphp
<div class="flex h-full flex-col">
    <header class="border-b border-base-300 px-5 py-4 pr-14">
        <h2 id="offer-drawer-title" class="text-lg font-bold leading-snug">{{ $group['subject_name'] }}</h2>
        <p class="mt-1 text-sm text-neutral"><span class="font-mono">{{ $group['subject_code'] }}</span> · Grupo <span class="font-mono">{{ $group['code'] }}</span> · {{ $group['career'] }}</p>
    </header>
    <div class="flex-1 space-y-6 overflow-y-auto px-5 py-4">
        <section>
            <dl class="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                <dt class="text-neutral">Carrera dueña</dt><dd>{{ $group['career'] }}</dd>
                <dt class="text-neutral">Semestre</dt><dd class="font-mono">{{ $group['semester'] }}</dd>
                <dt class="text-neutral">Horario</dt>
                <dd>@forelse ($group['schedule'] as $line)<div>{{ $line['days'] }} <span class="font-mono">{{ $line['time'] }}</span></div>@empty<span class="text-neutral">Sin horario</span>@endforelse</dd>
                <dt class="text-neutral">Salón</dt><dd class="font-mono">{{ $group['rooms'] === [] ? '—' : implode(', ', $group['rooms']) }}</dd>
                <dt class="text-neutral">Campus</dt><dd><span class="badge badge-neutral badge-soft badge-sm font-mono">{{ $group['campus_code'] }}</span> {{ $group['campus_name'] }}</dd>
                <dt class="text-neutral">Docente</dt>
                <dd>@if ($group['is_mine'])<b>Tú</b>@elseif ($group['teacher_name']){{ $group['teacher_name'] }}@else<span class="font-semibold text-warning">Sin docente</span>@endif</dd>
            </dl>
        </section>

        <section data-testid="offer-place">
            <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-neutral">Tu lugar</h3>
            @if ($group['rank'] === null)
                <p class="text-sm text-neutral">No estás en el ranking de este grupo.</p>
            @else
                <p class="flex items-baseline gap-2"><b class="font-mono text-4xl leading-none">{{ $group['rank'] }}</b>
                    <span class="text-sm text-neutral">de {{ $group['total'] }} docentes{{ $group['is_mine'] ? ' · asignado a ti' : '' }}</span></p>
                @if (array_intersect($group['flags'], array_keys($flagBadges)) !== [])
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @foreach (array_intersect_key($flagBadges, array_flip($group['flags'])) as [$flagTone, $flagLabel, $flagIcon])
                            <x-core::status-badge :tone="$flagTone" :label="$flagLabel" :icon="$flagIcon" />
                        @endforeach
                    </div>
                @endif
            @endif
        </section>

        @if ($b !== null)
            <section data-testid="offer-breakdown">
                <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-neutral">Tu desglose</h3>
                <table class="table table-sm">
                    <thead class="sr-only"><tr><th>Criterio</th><th>Valor</th><th>Puntos</th></tr></thead>
                    <tbody>
                        <tr><td>Horas base vigentes</td><td class="text-right font-mono">{{ $b['base_hours'] }} h</td><td class="text-right text-neutral">primer criterio</td></tr>
                        <tr><td>Veces impartida</td><td class="text-right font-mono">{{ $b['history_count'] }}</td><td class="text-right font-mono">{{ number_format($b['history_points'], 1) }}</td></tr>
                        <tr><td>Perfil en la materia</td><td class="text-right">{{ $b['qualification'] ?? 'Sin perfil' }}</td><td class="text-right font-mono">{{ number_format($b['qualification_points'], 1) }}</td></tr>
                        <tr><td>Evaluación docente</td><td class="text-right font-mono">{{ $b['evaluation'] === null ? 'sin evaluación' : number_format($b['evaluation'], 1) }}</td><td class="text-right font-mono">{{ number_format($b['evaluation_points'], 1) }}</td></tr>
                        <tr class="font-bold"><td>Puntaje</td><td></td><td class="text-right font-mono">{{ number_format($b['score'], 1) }}</td></tr>
                    </tbody>
                </table>
                @if ($b['remaining_hours'] !== null)
                    <p class="mt-3 text-sm" data-testid="offer-remaining">Te quedan <b class="font-mono">{{ $b['remaining_hours'] }} h</b>@if ($b['remaining_after_hours'] !== null) y con este grupo te quedarían <b class="font-mono">{{ $b['remaining_after_hours'] }} h</b>@endif.</p>
                @endif
            </section>
        @endif
    </div>
    @if ($group['is_mine'])
        <footer class="border-t border-base-300 px-5 py-3">
            <x-button label="Ver en Mi carga" icon="o-calendar-days" :link="route('staffing.my-load')" class="btn-outline btn-sm" />
        </footer>
    @endif
</div>
