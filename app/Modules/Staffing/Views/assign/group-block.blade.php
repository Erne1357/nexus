{{--
    Un grupo en la vista por materia: a la izquierda su clave, su carrera dueña, su horario (un bloque por renglón, sin
    partirse) y sus horas; a la derecha los tres primeros lugares de su ranking (staffing::assign.candidate), el motivo
    si se pidió desde ahí y sus acciones (staffing::assign.group-actions). Un grupo con docente va compacto, en un
    renglón: su docente (staffing::assign.assigned) y sus acciones, para que los asignados no tapen a los que faltan.
    «Ver el ranking completo» o «Cambiar» abre debajo el ranking completo con el historial, a todo el ancho, y al pie
    «Ocultar ranking» y «Siguiente sin docente».
--}}
@php($group = $row->group)
@php($open = in_array($group->id, $expanded, true))
@php($pendingHere = $pending !== null && $pending['group'] === $group->id)
@php($compact = $row->hasTeacher())
<article wire:key="group-{{ $group->id }}" data-group="{{ $group->id }}" data-testid="group-block" tabindex="-1"
    @class([
        'grid scroll-mt-[calc(var(--shell-top)+3.5rem)] gap-x-5 px-4 transition-shadow focus:outline-none md:grid-cols-[10.5rem_minmax(0,1fr)] motion-reduce:transition-none',
        'gap-y-2 py-2.5' => $compact,
        'gap-y-3 py-3.5' => ! $compact,
    ])>
    <div class="grid content-start gap-1">
        <span class="flex items-center gap-2">
            <span @class(['font-mono font-bold leading-none', 'text-[15px]' => $compact, 'text-xl' => ! $compact])>{{ $group->code }}</span>
            <span class="badge badge-sm badge-neutral badge-soft font-mono font-semibold" title="{{ $group->ownerCareer?->name }}">{{ $group->ownerCareer?->code }}</span>
        </span>
        <span class="flex flex-wrap gap-x-2 font-mono text-[12.5px] font-semibold md:grid">
            @foreach (explode(' · ', $row->schedule) as $block)
                <span class="whitespace-nowrap">{{ $block }}</span>
            @endforeach
        </span>
        @unless ($compact)
            <span class="text-[12.5px] text-neutral"><span class="font-mono tabular-nums">{{ $row->hours }}</span> h por semana</span>
        @endunless
    </div>

    <div class="@container grid min-w-0 content-start gap-2.5">
        @if ($compact)
            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5">
                @include('staffing::assign.assigned', ['row' => $row])
                @include('staffing::assign.group-actions', ['row' => $row, 'open' => $open])
            </div>
        @else
            @if ($row->rankingState === \App\Modules\Staffing\Enums\RankingState::Pending)
                <p class="flex min-h-11 items-center gap-2 rounded-box border border-dashed border-base-300 px-3 text-sm text-neutral" data-testid="ranking-pending">
                    <span class="loading loading-spinner loading-xs" aria-hidden="true"></span>Calculando el ranking…
                </p>
            @elseif ($row->rankingState === \App\Modules\Staffing\Enums\RankingState::Empty)
                <p class="flex min-h-11 items-center gap-2 rounded-box border border-dashed border-base-300 px-3 text-sm text-neutral" data-testid="ranking-empty">
                    <x-icon name="o-user-minus" class="h-4 w-4 shrink-0" />Ningún docente entró al ranking de este grupo.
                </p>
            @else
                @php($recommendedId = collect($row->candidates)->first(fn ($candidate) => ! $candidate->isFlagged())?->teacherId)
                <div class="grid gap-2 @[44rem]:grid-cols-3" data-testid="candidates">
                    @foreach ($row->candidates as $candidate)
                        @include('staffing::assign.candidate', ['group' => $group, 'candidate' => $candidate, 'size' => $row->rankingSize, 'recommended' => $candidate->teacherId === $recommendedId])
                    @endforeach
                </div>
                @if ($recommendedId === null)
                    <p class="flex items-center gap-2 text-[12.5px] text-neutral" data-testid="no-eligible">
                        <x-icon name="o-information-circle" class="h-4 w-4 shrink-0" aria-hidden="true" />Ninguno sin marcas: estos son los primeros del bloque final.
                    </p>
                @endif
            @endif

            @if ($pendingHere && ($pending['from'] === 'tarjetas' || ! $open))
                @include('staffing::assign.reason')
            @endif

            @if ($failure !== null && $failure['group'] === $group->id && $failure['from'] === 'tarjetas')
                @include('staffing::assign.failure')
            @endif

            <div class="flex flex-wrap items-center gap-2">
                @include('staffing::assign.group-actions', ['row' => $row, 'open' => $open])
            </div>
        @endif
    </div>

    @if ($open && isset($rankings[$group->id]))
        <div id="ranking-{{ $group->id }}" class="min-w-0 overflow-hidden rounded-box border border-base-300 md:col-span-2" data-testid="full-ranking">
            @include('staffing::assign.ranking', ['group' => $group, 'ranking' => $rankings[$group->id], 'currentTeacherId' => $group->teacher_id, 'capped' => true])
            <details class="group/history border-t border-base-300" @if ($rankings[$group->id]->rows === []) open @endif>
                <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-4 py-2.5 font-semibold hover:bg-base-200 [&::-webkit-details-marker]:hidden">
                    <x-icon name="o-chevron-right" class="h-4 w-4 transition-transform group-open/history:rotate-90 motion-reduce:transition-none" />
                    Historial de asignaciones
                    <span class="badge badge-sm badge-neutral badge-soft font-mono">{{ count($rankings[$group->id]->history) }}</span>
                </summary>
                @include('staffing::assign.history', ['group' => $group, 'history' => $rankings[$group->id]->history])
            </details>
            <div class="flex flex-wrap items-center gap-2 border-t border-base-300 px-2 py-1.5">
                <button type="button" wire:click="toggleRanking('{{ $group->id }}')" aria-expanded="true" aria-controls="ranking-{{ $group->id }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="hide-ranking-bottom">
                    <x-icon name="o-chevron-up" class="h-4 w-4" />Ocultar ranking
                </button>
                @include('staffing::assign.next-button', ['from' => $group->id, 'next' => $nextWithoutTeacher[$group->id] ?? null])
            </div>
        </div>
    @endif
</article>
