{{--
    Un docente del ranking completo (staffing::assign.ranking): lugar, nombre con su plaza, «otro depto.», «sin
    evaluación», sus avisos y sus marcas; sus cifras (en la tabla, una columna cada una; en caja angosta, con su
    etiqueta en una franja) y «Asignar» o «Asignado». El primero que puede tomarlo lleva el botón en primary; un
    marcado nunca. El motivo y los rechazos van bajo el renglón.
--}}
@php($current = $candidate->teacherId === $currentTeacherId)
@php($recommended = $candidate->teacherId === $recommendedId)
<div wire:key="rank-{{ $group->id }}-{{ $candidate->teacherId }}" data-teacher="{{ $candidate->teacherId }}" @class(['bg-info/10' => $current])>
    <div role="row" @class([
        'grid grid-cols-[1.75rem_minmax(0,1fr)_auto] items-center gap-x-2 gap-y-2 px-4 py-2.5',
        $grid,
        'text-secondary-muted' => $candidate->isFlagged(),
    ])>
        <span role="cell" class="col-start-1 row-start-1 self-start font-mono text-[15px] font-semibold tabular-nums @[46rem]:self-center" title="Lugar {{ $candidate->rank }} de {{ $size }}">{{ $candidate->rank }}</span>
        <div role="cell" class="col-start-2 row-start-1 grid min-w-0 gap-1">
            <span class="line-clamp-2 font-semibold leading-tight" title="{{ $candidate->teacherName }}">{{ $candidate->teacherName }}</span>
            <span class="flex flex-wrap items-center gap-1.5 text-[12.5px]">
                @include('staffing::assign.teacher-tags', ['positions' => $candidate->positions, 'fromOther' => $candidate->fromOtherDepartment])
                @if ($candidate->lacksEvaluation())
                    <span class="text-neutral">sin evaluación</span>
                @endif
                @include('staffing::assign.notices', ['candidate' => $candidate])
            </span>
            @include('staffing::assign.marks', ['candidate' => $candidate])
        </div>
        <div class="col-span-2 col-start-2 row-start-2 grid grid-cols-3 gap-x-4 gap-y-1.5 rounded-field bg-base-200 px-2.5 py-1.5 text-sm @[46rem]:contents">
            @foreach ([
                ['Base', $candidate->baseHours, true],
                ['Veces', $candidate->historyCount, true],
                ['Perfil', null, false],
                ['Eval.', $candidate->evaluationLabel() ?? '—', true],
                ['Puntaje', $candidate->scoreLabel(), true],
                ['Quedan', $candidate->remainingLabel() ?? '—', true],
            ] as [$metricLabel, $metricValue, $numeric])
                <span role="cell" @class(['grid min-w-0 gap-0.5 @[46rem]:row-start-1 @[46rem]:block', '@[46rem]:text-right' => $numeric])>
                    <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral @[46rem]:sr-only">{{ $metricLabel }}</span>
                    @if ($numeric)
                        <span class="whitespace-nowrap font-mono font-semibold tabular-nums">{{ $metricValue }}</span>
                    @else
                        @include('staffing::assign.qualification', ['qualification' => $candidate->qualification])
                    @endif
                </span>
            @endforeach
        </div>
        <span role="cell" class="col-start-3 row-start-1 self-start justify-self-end @[46rem]:col-start-9 @[46rem]:self-center">
            @if ($current)
                <x-core::status-badge tone="info" label="Asignado" icon="o-check" data-testid="current-teacher" />
            @elseif ($canAssign)
                <button type="button" wire:click="assign('{{ $group->id }}', '{{ $candidate->teacherId }}', 'ranking')" wire:loading.attr="disabled" wire:target="assign"
                    aria-label="Asignar a {{ $candidate->teacherName }}, lugar {{ $candidate->rank }}" data-testid="assign-button" data-rank="{{ $candidate->rank }}"
                    @if ($recommended) data-recommended="{{ $group->id }}" @endif
                    @class(['btn btn-sm max-md:h-11', 'btn-primary btn-soft' => $recommended, 'btn-ghost' => ! $recommended])><x-icon name="o-arrow-right-circle" class="h-4 w-4" />Asignar</button>
            @endif
        </span>
    </div>

    @if ($pending !== null && $pending['group'] === $group->id && $pending['teacher'] === $candidate->teacherId && $pending['from'] === 'ranking')
        <div class="px-4 pb-3 @[46rem]:ps-[3.25rem]">
            @include('staffing::assign.reason')
        </div>
    @endif
    @if ($failure !== null && $failure['group'] === $group->id && $failure['teacher'] === $candidate->teacherId && $failure['from'] === 'ranking')
        <div class="px-4 pb-3 @[46rem]:ps-[3.25rem]">
            @include('staffing::assign.failure')
        </div>
    @endif
</div>
