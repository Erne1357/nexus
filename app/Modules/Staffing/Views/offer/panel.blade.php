{{--
    Panel de un grupo en la oferta (tarea 3.10): el grupo, el lugar del docente, sus marcas (con la de choque, con cuál de
    sus grupos y a qué hora: $clashes) y SU desglose con sus columnas «Tuyo» y «Puntos». Nunca recibe las filas de otros
    docentes: TeacherOfferQuery solo lee el ranking con su id. `close()` es del x-drawer.
--}}
@use('App\Modules\Staffing\Enums\RankingMark')
@php($b = $group['breakdown'])
<div class="flex min-h-full flex-col">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="offer-drawer-title" class="text-lg font-bold leading-snug">{{ $group['subject_name'] }}</h2>
            <p class="mt-1 text-sm text-neutral"><span class="font-mono">{{ $group['subject_code'] }}</span> · Grupo <span class="font-mono">{{ $group['code'] }}</span> · {{ $group['career'] }}</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
    </header>
    <div class="relative flex-1 space-y-6 overflow-y-auto px-5 py-4">
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
            <h3 class="mb-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Tu lugar</h3>
            @if ($group['rank'] === null)
                <p class="text-sm text-neutral">No estás en el ranking de este grupo.</p>
            @else
                <p class="flex items-baseline gap-2"><b class="font-mono text-4xl leading-none">{{ $group['rank'] }}</b>
                    <span class="text-sm text-neutral">de <span class="font-mono">{{ $group['total'] }}</span> docentes{{ $group['is_mine'] ? ' · asignado a ti' : '' }}</span></p>
                @php($others = array_values(array_filter($group['marks'], fn ($mark) => $mark !== RankingMark::Clash)))
                @if ($others !== [])
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @foreach ($others as $mark)
                            <x-core::status-badge :tone="$mark->tone()" :label="$mark->label()" :icon="$mark->icon()" />
                        @endforeach
                    </div>
                @endif
                @if (in_array(RankingMark::Clash, $group['marks'], true))
                    <div class="mt-3 rounded-field border border-error/30 bg-error/10 px-3 py-2.5" data-testid="offer-clashes">
                        <p class="flex items-center gap-1.5 text-sm font-semibold text-error">
                            <x-icon :name="RankingMark::Clash->icon()" class="h-4 w-4 shrink-0" aria-hidden="true" />Choca con tu horario
                        </p>
                        @if ($clashes !== [])
                            <ul class="mt-1.5 space-y-1 text-sm">
                                @foreach ($clashes as $clash)
                                    <li><span class="font-mono font-semibold">{{ $clash['code'] }}</span> · {{ $clash['subject_name'] }} · <span class="font-mono">{{ $clash['campus_code'] }}</span>
                                        <span class="text-neutral">@foreach ($clash['schedule'] as $line){{ $line['days'] }} <span class="font-mono">{{ $line['time'] }}</span>@if (! $loop->last) · @endif @endforeach</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            @endif
        </section>

        @if ($b !== null)
            <section data-testid="offer-breakdown">
                <h3 class="mb-1 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Tu desglose</h3>
                <table class="table text-sm">
                    <thead>
                        <tr class="text-[11.5px] uppercase tracking-[0.09em] text-neutral">
                            <th class="ps-0 font-medium">Criterio</th>
                            <th class="text-right font-medium">Tuyo</th>
                            <th class="pe-0 text-right font-medium">Puntos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="ps-0">Horas base vigentes<span class="block text-xs text-neutral">Primer criterio del orden; no suma puntos</span></td>
                            <td class="text-right font-mono">{{ $b['base_hours'] }} h</td>
                            <td class="pe-0 text-right text-neutral">—</td>
                        </tr>
                        <tr><td class="ps-0">Veces impartida</td><td class="text-right font-mono">{{ $b['history_count'] }}</td><td class="pe-0 text-right font-mono">{{ number_format($b['history_points'], 1) }}</td></tr>
                        <tr><td class="ps-0">Perfil en la materia</td><td class="text-right">{{ $b['qualification'] ?? 'Sin perfil' }}</td><td class="pe-0 text-right font-mono">{{ number_format($b['qualification_points'], 1) }}</td></tr>
                        <tr><td class="ps-0">Evaluación docente</td><td @class(['text-right', 'font-mono' => $b['evaluation'] !== null, 'text-neutral' => $b['evaluation'] === null])>{{ $b['evaluation'] === null ? 'sin evaluación' : number_format($b['evaluation'], 1) }}</td><td class="pe-0 text-right font-mono">{{ number_format($b['evaluation_points'], 1) }}</td></tr>
                        <tr class="font-bold"><td class="ps-0">Puntaje</td><td></td><td class="pe-0 text-right font-mono">{{ number_format($b['score'], 1) }}</td></tr>
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
            <x-button label="Ver en Mi carga" icon="o-calendar-days" :link="route('staffing.my-load')" class="btn-outline max-md:h-11" />
        </footer>
    @endif
</div>
