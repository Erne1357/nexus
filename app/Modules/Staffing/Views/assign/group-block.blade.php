{{--
    Un grupo en la vista por materia: a la izquierda su clave, su carrera dueña, su horario y sus horas; a la derecha su
    docente (staffing::assign.assigned) o los tres primeros lugares de su ranking (staffing::assign.candidate), el
    motivo si se pidió desde ahí y «Ver el ranking completo» o «Cambiar», que abre debajo el ranking completo con el
    historial, a todo el ancho del renglón.
--}}
@php($group = $row->group)
@php($open = in_array($group->id, $expanded, true))
@php($pendingHere = $pending !== null && $pending['group'] === $group->id)
<article wire:key="group-{{ $group->id }}" data-group="{{ $group->id }}" data-testid="group-block"
    class="grid scroll-mt-[calc(var(--shell-top)+3.5rem)] gap-x-5 gap-y-3 px-4 py-3.5 md:grid-cols-[10.5rem_minmax(0,1fr)]">
    <div class="grid content-start gap-1">
        <span class="flex items-center gap-2">
            <span class="font-mono text-xl font-bold leading-none">{{ $group->code }}</span>
            <span class="badge badge-sm badge-neutral badge-soft font-mono font-semibold" title="{{ $group->ownerCareer?->name }}">{{ $group->ownerCareer?->code }}</span>
        </span>
        <span class="font-mono text-[13px] font-semibold">{{ $row->schedule }}</span>
        <span class="text-[12.5px] text-neutral"><span class="font-mono tabular-nums">{{ $row->hours }}</span> h por semana · {{ $board->campus->code }}</span>
    </div>

    <div class="@container grid min-w-0 content-start gap-2.5">
        @if ($row->hasTeacher())
            @include('staffing::assign.assigned', ['row' => $row])
        @elseif ($row->rankingState === \App\Modules\Staffing\Enums\RankingState::Pending)
            <p class="flex min-h-11 items-center gap-2 rounded-box border border-dashed border-base-300 px-3 text-sm text-neutral" data-testid="ranking-pending">
                <span class="loading loading-spinner loading-xs" aria-hidden="true"></span>Calculando el ranking…
            </p>
        @elseif ($row->rankingState === \App\Modules\Staffing\Enums\RankingState::Empty)
            <p class="flex min-h-11 items-center gap-2 rounded-box border border-dashed border-base-300 px-3 text-sm text-neutral" data-testid="ranking-empty">
                <x-icon name="o-user-minus" class="h-4 w-4 shrink-0" />Ningún docente entró al ranking de este grupo.
            </p>
        @else
            <div class="grid gap-2 @[44rem]:grid-cols-3" data-testid="candidates">
                @foreach ($row->candidates as $candidate)
                    @include('staffing::assign.candidate', ['group' => $group, 'candidate' => $candidate, 'size' => $row->rankingSize])
                @endforeach
            </div>
        @endif

        @if ($pendingHere && ($pending['from'] === 'tarjetas' || ! $open))
            @include('staffing::assign.reason')
        @endif

        @if ($failure !== null && $failure['group'] === $group->id && $failure['from'] === 'tarjetas')
            @include('staffing::assign.failure')
        @endif

        @if ($row->rankingSize > 0 || $row->hasTeacher())
            <div class="flex flex-wrap items-center gap-2">
                @if ($open)
                    <button type="button" wire:click="toggleRanking('{{ $group->id }}')" aria-expanded="true" aria-controls="ranking-{{ $group->id }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="toggle-ranking">
                        <x-icon name="o-chevron-up" class="h-4 w-4" />Ocultar ranking
                    </button>
                @elseif ($row->hasTeacher() && $canAssign)
                    <button type="button" wire:click="toggleRanking('{{ $group->id }}')" aria-expanded="false" aria-controls="ranking-{{ $group->id }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="change-teacher">
                        <x-icon name="o-arrows-right-left" class="h-4 w-4" />Cambiar
                    </button>
                @else
                    <button type="button" wire:click="toggleRanking('{{ $group->id }}')" aria-expanded="false" aria-controls="ranking-{{ $group->id }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="toggle-ranking">
                        <x-icon name="o-chevron-down" class="h-4 w-4" />Ver el ranking completo ({{ $row->rankingSize }})
                    </button>
                @endif
            </div>
        @endif
    </div>

    @if ($open && isset($rankings[$group->id]))
        <div id="ranking-{{ $group->id }}" class="min-w-0 overflow-hidden rounded-box border border-base-300 md:col-span-2" data-testid="full-ranking">
            @include('staffing::assign.ranking', ['group' => $group, 'ranking' => $rankings[$group->id], 'currentTeacherId' => $group->teacher_id])
            <details class="group/history border-t border-base-300" @if ($rankings[$group->id]->rows === []) open @endif>
                <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-4 py-2.5 font-semibold hover:bg-base-200 [&::-webkit-details-marker]:hidden">
                    <x-icon name="o-chevron-right" class="h-4 w-4 transition-transform group-open/history:rotate-90 motion-reduce:transition-none" />
                    Historial de asignaciones
                    <span class="badge badge-sm badge-neutral badge-soft font-mono">{{ count($rankings[$group->id]->history) }}</span>
                </summary>
                @include('staffing::assign.history', ['group' => $group, 'history' => $rankings[$group->id]->history])
            </details>
        </div>
    @endif
</article>
