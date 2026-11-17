{{--
    El drawer de una materia en «Demanda por materia» (maqueta C, `x-drawer` a la derecha): la clave, el nombre, el
    departamento, las horas a la semana y si requiere laboratorio; el desglose por campus (elegibles, esperados,
    sugeridos, pedidos, la diferencia y las horas a la semana de lo pedido) con «Todo el Tec»; el desglose por carrera y
    campus con el estado de su propuesta, su semestre y el método de la proyección («Revisar» en una materia de
    especialidad); y el historial de los últimos periodos del mismo tipo, por campus: grupos, cupo e inscritos
    (SubjectGroupHistoryQuery) y cuántos la cursaron, la aprobaron y la reprobaron (TermOccupancyQuery). Al pie, la
    bandeja del departamento o la propuesta de la carrera, si el usuario las ve. `close()` es del x-drawer.
--}}
@php($subject = $drawer->subject)
@php($hours = $drawer->weeklyHours())
@php($breakdown = $drawer->breakdown())
@php($lensId = $board->career?->getKey())
@php($signed = fn (int $n): string => $n > 0 ? "+{$n}" : ($n < 0 ? '−'.abs($n) : '0'))
@php($label = 'text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral')
<div class="flex h-dvh flex-col" data-testid="demand-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <p class="font-mono text-[15px] font-semibold">{{ $subject->code }}</p>
            <h2 id="demand-drawer-title" class="mt-0.5 text-xl font-bold leading-tight">{{ $subject->name }}</h2>
            <p class="mt-1 text-sm text-neutral">
                @if ($subject->department)
                    <span class="font-mono">{{ $subject->department->code }}</span> · {{ $subject->department->name }} ·
                @endif
                <span class="font-mono">{{ $hours }}</span> h a la semana
                @if ($subject->needs_lab)
                    · Requiere laboratorio
                @endif
            </p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" data-testid="demand-drawer-close" />
    </header>

    <div class="flex-1 space-y-6 overflow-y-auto px-5 py-4">
        <section aria-labelledby="demand-by-campus-title" data-testid="demand-by-campus">
            <h3 id="demand-by-campus-title" class="{{ $label }}">Por campus</h3>
            <div class="mt-2 overflow-x-auto rounded-box border border-base-300">
                <table class="table table-sm">
                    <thead class="bg-base-200">
                        <tr class="{{ $label }}">
                            <th scope="col"><span class="sr-only">Campus</span></th>
                            <th scope="col" class="text-end">Elegibles</th>
                            <th scope="col" class="text-end">Esperados</th>
                            <th scope="col" class="text-end">Sugeridos</th>
                            <th scope="col" class="text-end">Pedidos</th>
                            <th scope="col" class="text-end">Diferencia</th>
                            <th scope="col" class="text-end" title="Horas a la semana de lo pedido: grupos por {{ $hours }} h">Horas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([...$breakdown['campuses'], ['campus' => false, ...$breakdown['total']]] as $line)
                            @php($cell = $line['cell'])
                            @php($isTotal = $line['campus'] === false)
                            <tr @class(['bg-base-200 font-semibold' => $isTotal])>
                                <th scope="row" class="whitespace-nowrap font-medium">
                                    @if ($isTotal)
                                        Todo el Tec
                                    @elseif ($line['campus'] !== null)
                                        <span class="inline-flex items-center gap-2"><x-core::campus-tag :campus="$line['campus']" decorative />{{ $line['campus']->name }}</span>
                                    @else
                                        Sin campus
                                    @endif
                                </th>
                                <td class="text-end font-mono tabular-nums">{{ $line['eligible'] }}</td>
                                <td class="text-end font-mono tabular-nums">{{ $line['expected'] }}</td>
                                <td class="text-end font-mono tabular-nums">{{ $cell->suggested }}</td>
                                <td class="text-end font-mono font-semibold tabular-nums">{{ $cell->counted }}</td>
                                <td @class(['whitespace-nowrap text-end', 'text-warning' => $cell->large])>
                                    @if ($cell->difference() === 0)
                                        <span class="font-mono text-neutral">0</span>
                                    @else
                                        <span class="inline-flex items-center gap-1">
                                            @if ($cell->large)
                                                <x-icon :name="$cell->difference() > 0 ? 'o-arrow-trending-up' : 'o-arrow-trending-down'" class="h-3.5 w-3.5" aria-hidden="true" />
                                            @endif
                                            <span class="font-mono font-semibold tabular-nums">{{ $signed($cell->difference()) }}</span>
                                            @if ($cell->percent() !== null)
                                                <small class="font-mono tabular-nums">{{ $signed($cell->percent()) }} %</small>
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="text-end font-mono tabular-nums">{{ $cell->counted * $hours }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-labelledby="demand-by-career-title" data-testid="demand-by-career">
            <h3 id="demand-by-career-title" class="{{ $label }}">Por carrera</h3>
            <div class="mt-2 overflow-x-auto rounded-box border border-base-300">
                <table class="table table-sm">
                    <thead class="bg-base-200">
                        <tr class="{{ $label }}">
                            <th scope="col">Carrera</th>
                            @if (count($board->campuses) > 0)
                                <th scope="col">Campus</th>
                            @endif
                            <th scope="col" class="text-end" title="Semestre en la retícula de la carrera">Sem.</th>
                            <th scope="col" class="text-end">Elegibles</th>
                            <th scope="col" class="text-end">Esperados</th>
                            <th scope="col" class="text-end">Sugeridos</th>
                            <th scope="col" class="text-end">Pedidos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($drawer->rows as $line)
                            @php($mine = $line->career->getKey() === $lensId)
                            <tr @class(['bg-info/8' => $mine]) data-testid="demand-drawer-row-{{ $line->career->code }}-{{ $line->campus?->code ?? 'sin-campus' }}">
                                <th scope="row" class="font-normal">
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        <span class="font-mono font-bold" title="{{ $line->career->name }}">{{ $line->career->code }}</span>
                                        @include('academic-load::demand.state', ['state' => $line->state])
                                        @if ($mine)
                                            <span class="text-xs text-neutral">(tu carrera)</span>
                                        @endif
                                    </span>
                                    <span class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-neutral">
                                        <span>{{ $line->method?->label() ?? 'Sin proyección' }}</span>
                                        @if ($line->needsReview)
                                            <span class="inline-flex items-center gap-1 font-medium text-warning" title="Materia de especialidad: depende de qué especialidad elige cada alumno."><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5" aria-hidden="true" />Revisar</span>
                                        @endif
                                    </span>
                                </th>
                                @if (count($board->campuses) > 0)
                                    <td><x-core::campus-tag :campus="$line->campus" /></td>
                                @endif
                                <td class="text-end font-mono tabular-nums">{{ $line->semester ?? '–' }}</td>
                                <td class="text-end font-mono tabular-nums">{{ $line->eligible }}</td>
                                <td class="text-end font-mono tabular-nums">{{ $line->expected }}</td>
                                <td class="text-end font-mono tabular-nums">{{ $line->suggested }}</td>
                                <td class="text-end font-mono tabular-nums">
                                    @if ($line->state->counts())
                                        <b class="font-semibold">{{ $line->requested }}</b>
                                    @else
                                        <span class="text-neutral" title="Sin propuesta enviada: cuenta lo sugerido">{{ $line->suggested }}*</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (collect($drawer->rows)->contains(fn ($line) => ! $line->state->counts()))
                <p class="mt-2 text-sm text-neutral">* Sin propuesta enviada: cuenta lo sugerido.</p>
            @endif
            @if (collect($drawer->rows)->contains(fn ($line) => $line->method === \App\Modules\Demand\Enums\ForecastMethod::FirstSemester))
                <p class="mt-1 flex items-start gap-1.5 text-sm text-neutral"><x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />En primer semestre, los esperados incluyen el nuevo ingreso histórico.</p>
            @endif
            @if (collect($drawer->rows)->contains(fn ($line) => $line->needsReview))
                <p class="mt-1 flex items-start gap-1.5 text-sm text-neutral"><x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />«Revisar»: materia de especialidad; lo sugerido depende de qué especialidad elige cada alumno.</p>
            @endif
        </section>

        @if ($history !== null && $history['campuses'] !== [])
            <section aria-labelledby="demand-history-title" data-testid="demand-history">
                <h3 id="demand-history-title" class="{{ $label }}">Historia de la materia en {{ $history['label'] }}</h3>
                <div class="mt-2 space-y-4">
                    @foreach ($history['campuses'] as $entry)
                        @php($any = collect($entry['terms'])->contains(fn ($term) => $term['groups'] !== null || $term['students'] !== null))
                        <div data-testid="demand-history-{{ $entry['campus']?->code ?? 'sin-campus' }}">
                            <p class="flex items-center gap-2 font-medium">
                                @if ($entry['campus'])
                                    <x-core::campus-tag :campus="$entry['campus']" decorative />{{ $entry['campus']->name }}
                                @else
                                    Sin campus
                                @endif
                            </p>
                            @if (! $any)
                                <p class="mt-1 text-sm text-neutral">Sin historia en {{ $history['label'] }}.</p>
                            @else
                                <div class="mt-1.5 overflow-x-auto rounded-box border border-base-300">
                                    <table class="table table-sm">
                                        <thead class="bg-base-200">
                                            <tr class="{{ $label }}">
                                                <th scope="col"><span class="sr-only">Dato</span></th>
                                                @foreach ($entry['terms'] as $term)
                                                    <th scope="col" class="text-end font-mono">{{ $term['year'] }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach (['groups' => 'Grupos', 'capacity' => 'Cupo', 'enrolled' => 'Inscritos', 'students' => 'Cursaron', 'passed' => 'Aprobaron', 'failed' => 'Reprobaron'] as $key => $rowLabel)
                                                <tr>
                                                    <th scope="row" class="font-medium">{{ $rowLabel }}</th>
                                                    @foreach ($entry['terms'] as $term)
                                                        <td class="text-end font-mono tabular-nums">@if ($term[$key] === null)<span class="text-neutral/70" aria-hidden="true">–</span><span class="sr-only">Sin dato</span>@else{{ $term[$key] }}@endif</td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 flex items-start gap-1.5 text-sm text-neutral"><x-icon name="o-circle-stack" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />Todas las carreras juntas. Grupos, cupo e inscritos, del SII migrado y de lo publicado en Nexus; quienes la cursaron, aprobaron y reprobaron, del kárdex.</p>
            </section>
        @endif
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-base-300 bg-base-100 px-5 py-3">
        <div class="flex flex-wrap gap-2 max-md:w-full">
            @if ($links['proposal'])
                <a href="{{ $links['proposal'] }}" wire:navigate class="btn btn-ghost max-md:h-11 max-md:w-full" data-testid="demand-open-proposal"><x-icon name="o-arrow-top-right-on-square" class="h-5 w-5" aria-hidden="true" />Abrir en la propuesta de {{ $board->career->code }}</a>
            @elseif ($board->career !== null)
                @php($lensState = collect($board->careers)->first(fn ($column) => $column['career']->getKey() === $board->career->getKey())['state'] ?? \App\Modules\AcademicLoad\Enums\DemandProposalState::Missing)
                <p class="flex items-center gap-2 text-sm text-neutral" data-testid="demand-proposal-state">Propuesta de <span class="font-mono font-semibold text-base-content">{{ $board->career->code }}</span>@include('academic-load::demand.state', ['state' => $lensState])</p>
            @endif
            @if ($links['inbox'])
                <a href="{{ $links['inbox'] }}" wire:navigate class="btn btn-ghost max-md:h-11 max-md:w-full" data-testid="demand-open-inbox"><x-icon name="o-arrow-top-right-on-square" class="h-5 w-5" aria-hidden="true" />Ver en la bandeja</a>
            @endif
        </div>
        <x-button label="Cerrar" class="max-md:h-11 max-md:w-full" @click="close()" />
    </footer>
</div>
