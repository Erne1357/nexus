{{--
    La evidencia de la proyección de una fila de la propuesta (tarea 5.6, opción C), la misma en la tarjeta de «Revisar
    proyección» y en el panel de la materia: los elegibles (los posibles de la fila, el elegible ideal de la proyección y
    cuántos dependen de aprobar lo que cursan hoy), las tasas y cuántos periodos las dieron, los repetidores, la cuenta de
    los esperados y de los grupos en Mono, y el historial corto de los periodos del mismo tipo (grupos, lugares, inscritos
    y reprobados). Las cifras son las de la fila de la proyección tal como vienen, sin recalcular la fórmula: el resultado
    de cada cuenta es el que guardó Demand (fórmula v2: repetidores = cursando × reprobación × (1 − no inscripción)), con las
    tasas en porcentaje de hasta 2 decimales. Los
    grupos se cuentan «esperados ÷ tamaño hacia arriba» con el tamaño de la materia (25 con laboratorio, 35 sin él); si con
    el tamaño de hoy no dan los grupos guardados, solo se dice cuántos.

    `$forecastRow` es la ProposalForecastRow; `$history`, los renglones de ProposalForecastQuery::history o null.
--}}
@php
    $forecastData = $forecastRow->forecast;
    $forecastItem = $forecastRow->item;
    // En la cuenta, cada tasa como porcentaje con hasta 2 decimales («11.25 %»), como las lee la gente (decidido el 17 de noviembre de 2026).
    $rate = fn (float $value): string => (rtrim(rtrim(number_format($value * 100, 2, '.', ''), '0'), '.') ?: '0').' %';
    $percent = fn (float $value): int => (int) round($value * 100);
    $where = $forecastRow->campus?->code;
    $termType = $projection->termType();
    $evidenceLabel = 'text-[12.5px] text-neutral';
    $evidenceRow = 'grid gap-x-3 sm:grid-cols-[6.5rem_minmax(0,1fr)] sm:items-baseline';
@endphp
<div class="min-w-0 space-y-3">
    @if ($forecastData === null)
        <p class="text-sm text-neutral">Ya no hay proyección de esta materia{{ $where ? ' en '.$where : '' }}; la última sugería <span class="font-mono">{{ $forecastItem->suggested_expected_students }}</span> esperados y <span class="font-mono">{{ $forecastItem->suggested_groups }}</span> grupos.</p>
    @else
        @php
            $size = $projection->settings->groupSizes->sizeFor((bool) $forecastRow->subject->subject->needs_lab);
            $withRates = $forecastData->non_enrollment_rate !== null && $forecastData->failure_rate !== null;
            $nonEnrollment = $withRates ? $rate($forecastData->non_enrollment_rate) : null;
        @endphp
        <dl class="space-y-1 text-sm" data-testid="forecast-evidence">
            <div class="{{ $evidenceRow }}">
                <dt class="{{ $evidenceLabel }}">Elegibles</dt>
                <dd>
                    <span class="font-mono font-semibold">{{ $forecastItem->eligible_students }}</span> posibles · <span class="font-mono font-semibold">{{ $forecastData->eligible_students }}</span> en la proyección
                    @if ($forecastData->pending_prerequisite_students > 0)
                        <span class="text-neutral">· <span class="font-mono">{{ $forecastData->pending_prerequisite_students }}</span> dependen de aprobar algo que cursan hoy</span>
                    @endif
                </dd>
            </div>
            <div class="{{ $evidenceRow }}">
                <dt class="{{ $evidenceLabel }}">Tasas</dt>
                <dd>
                    @if ($withRates)
                        no inscripción <span class="font-mono font-semibold">{{ $percent($forecastData->non_enrollment_rate) }}</span> % · reprobación <span class="font-mono font-semibold">{{ $percent($forecastData->failure_rate) }}</span> %
                        <span class="text-neutral">·
                            @if ($forecastData->method === \App\Modules\Demand\Enums\ForecastMethod::DepartmentFallback)
                                de {{ $forecastRow->subject->subject->department->code }}: la materia no tiene historia{{ $where ? ' en '.$where : '' }}
                            @else
                                promedio de <span class="font-mono">{{ $forecastData->history_terms_used }}</span> {{ $termType }}
                            @endif
                        </span>
                    @else
                        <span class="text-neutral">Sin historia de la materia ni de su departamento: se espera a todos los elegibles.</span>
                    @endif
                </dd>
            </div>
            @if ($withRates)
                <div class="{{ $evidenceRow }}">
                    <dt class="{{ $evidenceLabel }}">Repetidores</dt>
                    <dd><span class="font-mono">{{ $forecastData->current_students }}</span> cursando hoy <span class="font-mono"><span class="whitespace-nowrap">× {{ $rate($forecastData->failure_rate) }}</span> <span class="whitespace-nowrap">× (1 − {{ $nonEnrollment }})</span></span> = <span class="font-mono font-semibold">{{ $forecastData->expected_repeaters }}</span></dd>
                </div>
            @endif
            <div class="{{ $evidenceRow }}">
                <dt class="{{ $evidenceLabel }}">Esperados</dt>
                <dd class="font-medium"><span class="font-mono">{{ $forecastData->eligible_students }}@if ($withRates) <span class="whitespace-nowrap">× (1 − {{ $nonEnrollment }})</span> <span class="whitespace-nowrap">+ {{ $forecastData->expected_repeaters }}</span>@endif @if ($forecastData->new_students !== null)<span class="whitespace-nowrap">+ {{ $forecastData->new_students }}</span>@endif</span>@if ($forecastData->new_students !== null) <span class="text-neutral">de nuevo ingreso</span>@endif = <span class="font-mono font-semibold">{{ $forecastData->expected_students }}</span></dd>
            </div>
            <div class="{{ $evidenceRow }}">
                <dt class="{{ $evidenceLabel }}">Grupos</dt>
                <dd class="font-medium">
                    @if ((int) ceil($forecastData->expected_students / $size) === $forecastData->suggested_groups)
                        <span class="font-mono">{{ $forecastData->expected_students }} ÷ {{ $size }}</span> hacia arriba = <span class="font-mono font-semibold">{{ $forecastData->suggested_groups }}</span> <span class="font-normal text-neutral">· {{ $forecastRow->subject->subject->needs_lab ? "con laboratorio, {$size} por grupo" : "{$size} por grupo" }}</span>
                    @else
                        → <span class="font-mono font-semibold">{{ $forecastData->suggested_groups }}</span> {{ $forecastData->suggested_groups === 1 ? 'grupo' : 'grupos' }}
                    @endif
                </dd>
            </div>
        </dl>
    @endif

    @if ($history)
        @php
    $withData = collect($history)->contains(fn (array $term): bool => $term['groups'] !== null || $term['failed'] !== null);
@endphp
        @if ($withData)
            <div class="max-w-full overflow-x-auto">
                <table class="w-max text-[12.5px] tabular-nums" data-testid="forecast-history">
                    <caption class="sr-only">Historial de los últimos {{ $termType }}{{ $where ? ' en '.$where : '' }}</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="pe-3 text-left font-normal"><span class="sr-only">Dato</span></th>
                            @foreach ($history as $term)
                                <th scope="col" class="px-2.5 py-1 text-right font-mono font-medium text-neutral">{{ $term['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['groups' => 'Grupos', 'capacity' => 'Lugares', 'enrolled' => 'Inscritos', 'failed' => 'Reprobaron'] as $field => $fieldLabel)
                            <tr class="border-t border-base-300">
                                <th scope="row" class="py-1 pe-3 text-left font-medium text-neutral">{{ $fieldLabel }}</th>
                                @foreach ($history as $term)
                                    <td class="px-2.5 py-1 text-right font-mono">{{ $term[$field] ?? '–' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-[12.5px] text-neutral">Sin grupos ni kárdex de esta materia{{ $where ? ' en '.$where : '' }} en los últimos {{ count($history) }} {{ $termType }}.</p>
        @endif
    @endif
</div>
