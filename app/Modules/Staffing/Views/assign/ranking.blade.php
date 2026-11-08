{{--
    El ranking completo de un grupo, el mismo en las dos vistas: lugar, docente (su plaza, «otro depto.», «sin
    evaluación» y sus marcas), horas base, veces que impartió la materia, perfil, evaluación, puntaje, horas que le
    quedan (sin y con el grupo) y «Asignar» (o «Asignado» para el docente vigente). Los marcados (sin horas, choque, no
    disponible) van al final, bajo un subtítulo que dice qué marcas hay (reglas-de-negocio.md, «Un solo bloque final»).
    Con la caja de 46rem o más (consulta de contenedor) es una tabla de nueve columnas; debajo, cada docente ocupa dos
    renglones: lugar, nombre y botón, y sus cifras con su etiqueta. El motivo y los rechazos van bajo el renglón.
--}}
@php($grid = '@[46rem]:grid-cols-[1.75rem_minmax(10rem,1fr)_2.75rem_2.75rem_6.25rem_3rem_3.75rem_4.75rem_6.75rem]')
@php($flagged = array_values(array_filter($ranking->rows, fn ($candidate) => $candidate->isFlagged())))
@php($flaggedMarks = array_intersect_key(\App\Modules\Staffing\Queries\RankingRow::MARKS, array_merge([], ...array_map(fn ($candidate) => $candidate->marks(), $flagged))))
@php($size = count($ranking->rows))
@php($flaggedShown = false)
<div class="@container" data-testid="ranking">
    @if ($ranking->rows === [])
        <p class="px-4 py-6 text-center text-sm text-neutral">Ningún docente entró al ranking de este grupo.</p>
    @else
        <div role="table" aria-label="Ranking de {{ $group->code }} · {{ $group->subject?->name }}">
            <div role="rowgroup">
                <div role="row" @class(['hidden items-center gap-x-2.5 border-b border-base-300 bg-secondary/40 px-4 py-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted @[46rem]:grid', $grid])>
                    <span role="columnheader">#</span>
                    <span role="columnheader">Docente</span>
                    <span role="columnheader" class="text-right">Base</span>
                    <span role="columnheader" class="text-right">Veces</span>
                    <span role="columnheader">Perfil</span>
                    <span role="columnheader" class="text-right">Eval.</span>
                    <span role="columnheader" class="text-right">Puntaje</span>
                    <span role="columnheader" class="text-right">Quedan</span>
                    <span role="columnheader"><span class="sr-only">Asignar</span></span>
                </div>
            </div>
            <div role="rowgroup" class="divide-y divide-base-300">
                @foreach ($ranking->rows as $candidate)
                    @if ($candidate->isFlagged() && ! $flaggedShown)
                        @php($flaggedShown = true)
                        <div role="row" class="flex flex-wrap items-center gap-x-2 bg-secondary/40 px-4 py-1.5 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted" data-testid="flagged-tier">
                            <span role="cell">Al final · {{ implode(' · ', array_map('mb_strtolower', array_values($flaggedMarks))) }} <span class="font-mono tabular-nums">{{ count($flagged) }}</span></span>
                        </div>
                    @endif
                    @php($current = $candidate->teacherId === $currentTeacherId)
                    <div wire:key="rank-{{ $group->id }}-{{ $candidate->teacherId }}" data-teacher="{{ $candidate->teacherId }}" @class(['bg-info/10' => $current])>
                        <div role="row" @class([
                            'grid grid-cols-[1.75rem_minmax(0,1fr)_auto] items-center gap-x-2.5 gap-y-2 px-4 py-2.5',
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
                                </span>
                                @include('staffing::assign.marks', ['candidate' => $candidate])
                            </div>
                            <div class="col-span-2 col-start-2 row-start-2 grid grid-cols-3 gap-x-3 gap-y-1.5 rounded-field bg-base-200 px-2.5 py-1.5 text-sm @[46rem]:contents">
                                @foreach ([
                                    ['Base', $candidate->baseHours, true],
                                    ['Veces', $candidate->historyCount, true],
                                    ['Perfil', null, false],
                                    ['Eval.', $candidate->evaluationLabel() ?? '—', true],
                                    ['Puntaje', $candidate->scoreLabel(), true],
                                    ['Quedan', $candidate->remainingLabel() ?? '—', true],
                                ] as [$metricLabel, $metricValue, $numeric])
                                    <span role="cell" @class(['grid min-w-0 gap-0.5 @[46rem]:row-start-1 @[46rem]:block', '@[46rem]:text-right' => $numeric])>
                                        <span class="text-[11px] font-medium uppercase tracking-[0.09em] text-neutral @[46rem]:sr-only">{{ $metricLabel }}</span>
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
                                        @class(['btn btn-sm max-md:h-11', 'btn-primary btn-soft' => $candidate->rank === 1, 'btn-ghost' => $candidate->rank !== 1])><x-icon name="o-arrow-right-circle" class="h-4 w-4" />Asignar</button>
                                @endif
                            </span>
                        </div>

                        @if ($pending !== null && $pending['group'] === $group->id && $pending['teacher'] === $candidate->teacherId && $pending['from'] === 'ranking')
                            <div class="px-4 pb-3 @[46rem]:ps-[3.375rem]">
                                @include('staffing::assign.reason')
                            </div>
                        @endif
                        @if ($failure !== null && $failure['group'] === $group->id && $failure['teacher'] === $candidate->teacherId && $failure['from'] === 'ranking')
                            <div class="px-4 pb-3 @[46rem]:ps-[3.375rem]">
                                @include('staffing::assign.failure')
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
