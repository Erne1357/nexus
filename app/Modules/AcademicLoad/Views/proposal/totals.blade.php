{{--
    Los totales de la propuesta (academic-load::pages.proposal): las materias con grupos de todas las de la propuesta
    («48 / 49»), los grupos y los alumnos posibles (los distintos que pueden llevar alguna materia, congelados al
    armarla; los alumnos esperados no se suman entre materias: un alumno cuenta en cada una que puede llevar), los dos
    con los de cada campus si hay más de uno («C1 178 · C2 46», «C1 612 · C2 174»; M6, maqueta U1 opción A). En la retícula van en el renglón de la vista, compactos y
    al ras de la retícula, para no gastar un renglón entero (a 1366 x 768 cada renglón cuenta); en la tabla, en una
    barra pegada al pie de la ventana, para verlos mientras se captura. `$floating` (opcional) la pega al pie.
--}}
@php
    $split = fn (string $total): array => count($columns) > 1
        ? array_map(fn ($key) => ['campus' => $columns[$key]['campus'], 'value' => $overview->totals[$total][$key] ?? 0], array_keys($columns))
        : [];
    $figures = [
        ['Materias con grupos', $overview->totals['subjects'], $overview->totals['subjects_total'], []],
        ['Grupos', $overview->totals['groups'], null, $split('groups_by_campus')],
        ['Alumnos posibles', $overview->totals['eligible_students'], null, $split('eligible_by_campus')],
    ];
@endphp
@if ($floating ?? false)
    <div class="sticky bottom-3 z-10 grid grid-cols-3 rounded-box border border-base-300 bg-base-100 shadow-float" data-testid="proposal-totals">
        @foreach ($figures as [$label, $value, $of, $byCampus])
            <div class="flex min-w-0 flex-col justify-start gap-0.5 border-base-300 px-3 py-2 sm:px-5 md:gap-1 md:py-3 [&:nth-child(n+2)]:border-l">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</span>
                <span class="font-mono text-xl font-semibold tabular-nums md:text-2xl">{{ $value }}@if ($of !== null)<span class="font-normal text-neutral"> / {{ $of }}</span>@endif</span>
                @if ($byCampus !== [])
                    <span class="flex flex-wrap items-center gap-x-2.5 gap-y-1" data-testid="totals-by-campus">
                        @foreach ($byCampus as $entry)
                            <span class="inline-flex items-center gap-1.5"><x-academic-load::campus-tag :campus="$entry['campus']" /><span class="font-mono text-sm font-semibold tabular-nums">{{ $entry['value'] }}</span></span>
                        @endforeach
                    </span>
                @endif
            </div>
        @endforeach
    </div>
@else
    <dl class="flex flex-wrap items-baseline gap-x-6 gap-y-1" data-testid="proposal-totals">
        @foreach ($figures as [$label, $value, $of, $byCampus])
            <div class="flex items-baseline gap-2">
                <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</dt>
                <dd class="font-mono text-lg font-semibold tabular-nums">{{ $value }}@if ($of !== null)<span class="font-normal text-neutral"> / {{ $of }}</span>@endif
                    @if ($byCampus !== [])
                        <span class="ms-1 font-sans text-[12.5px] font-normal text-neutral" data-testid="totals-by-campus">{{ collect($byCampus)->map(fn ($entry) => $entry['campus']?->code.' '.$entry['value'])->implode(' · ') }}</span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif
