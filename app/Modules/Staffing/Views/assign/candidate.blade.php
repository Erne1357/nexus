{{--
    Uno de los tres primeros lugares de un grupo sin docente, en la vista por materia: su lugar y su nombre; su plaza,
    «otro depto.», su perfil, las veces que impartió la materia, su evaluación y sus avisos; sus marcas; sus horas base
    y su puntaje con el mismo peso (el ranking va primero por horas base), las horas que le quedan y «Asignar». El
    primero que puede tomarlo (`recommended`) lleva el borde y el botón en primary; uno con marca nunca.
--}}
@php($recommended ??= false)
<div @class(['grid min-w-0 content-between gap-2 rounded-box border bg-base-100 px-3 py-2.5', 'border-primary/50' => $recommended, 'border-base-300' => ! $recommended])
    data-testid="candidate" data-teacher="{{ $candidate->teacherId }}" @if ($recommended) data-recommended="{{ $group->id }}" @endif>
    <div class="grid gap-1.5">
        <p class="flex min-w-0 items-baseline gap-2">
            <span class="font-mono text-[15px] font-bold tabular-nums" title="Lugar {{ $candidate->rank }} de {{ $size }}"><span class="sr-only">Lugar </span>{{ $candidate->rank }}</span>
            <span class="truncate font-semibold" title="{{ $candidate->teacherName }}">{{ $candidate->teacherName }}</span>
        </p>
        <p class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[12.5px]">
            @include('staffing::assign.teacher-tags', ['positions' => $candidate->positions, 'fromOther' => $candidate->fromOtherDepartment])
            @include('staffing::assign.qualification', ['qualification' => $candidate->qualification])
            <span><span class="font-mono font-semibold tabular-nums">{{ $candidate->historyCount }}</span> {{ $candidate->historyCount === 1 ? 'vez' : 'veces' }}</span>
            @if ($candidate->lacksEvaluation())
                <span class="text-neutral">sin evaluación</span>
            @else
                <span>eval. <span class="font-mono font-semibold tabular-nums">{{ $candidate->evaluationLabel() }}</span></span>
            @endif
            @include('staffing::assign.notices', ['candidate' => $candidate])
        </p>
        @include('staffing::assign.marks', ['candidate' => $candidate])
    </div>
    <div class="flex items-end justify-between gap-3">
        <span class="grid text-[12.5px] leading-snug">
            <span class="flex flex-wrap gap-x-3">
                <span>Base <span class="font-mono font-semibold tabular-nums">{{ $candidate->baseHours }}</span> h</span>
                <span>Puntaje <span class="font-mono font-semibold tabular-nums">{{ $candidate->scoreLabel() }}</span></span>
            </span>
            @if ($candidate->remainingLabel() !== null)
                <span>Quedan <span class="font-mono font-semibold tabular-nums">{{ $candidate->remainingLabel() }}</span></span>
            @endif
        </span>
        @if ($canAssign)
            <button type="button" wire:click="assign('{{ $group->id }}', '{{ $candidate->teacherId }}', 'tarjetas')" wire:loading.attr="disabled" wire:target="assign"
                aria-label="Asignar a {{ $candidate->teacherName }}, lugar {{ $candidate->rank }}" data-testid="assign-button" data-rank="{{ $candidate->rank }}"
                @class(['btn btn-sm max-md:h-11', 'btn-primary btn-soft' => $recommended, 'btn-ghost' => ! $recommended])><x-icon name="o-arrow-right-circle" class="h-4 w-4" />Asignar</button>
        @endif
    </div>
</div>
