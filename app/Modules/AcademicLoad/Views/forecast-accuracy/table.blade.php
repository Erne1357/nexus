{{--
    La vista «Tabla», gemela accesible de la gráfica: por carrera, el error, el de R1, cuánto mejora la fórmula (en
    puntos), las materias dentro del 15 %, el sesgo en alumnos por materia y la confianza; la de menor error primero.
    Debajo de 1024 px, renglones apilados. Tocar una carrera abre sus materias. `$report` (ForecastReport).
--}}
<section class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="accuracy-table">
    <div class="hidden overflow-x-auto lg:block">
        <table class="w-full min-w-[46rem] text-sm">
            <caption class="sr-only">Error de la proyección por carrera contra la regla de R1 y la meta del 15 %</caption>
            <thead class="bg-base-200">
                <tr class="whitespace-nowrap text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                    <th scope="col" class="px-4 py-2.5 text-start font-medium">Carrera</th>
                    <th scope="col" class="px-3 py-2.5 text-end font-medium">Error</th>
                    <th scope="col" class="px-3 py-2.5 text-end font-medium">Error R1</th>
                    <th scope="col" class="px-3 py-2.5 text-end font-medium">Mejora <span class="block text-[11.5px] normal-case tracking-normal">puntos</span></th>
                    <th scope="col" class="px-3 py-2.5 text-end font-medium">Dentro del 15&nbsp;%</th>
                    <th scope="col" class="px-3 py-2.5 text-end font-medium">Sesgo <span class="block text-[11.5px] normal-case tracking-normal">alumnos por materia</span></th>
                    <th scope="col" class="px-4 py-2.5 text-start font-medium">Confianza</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-300">
                @foreach ($report->careers as $career)
                    @php($metrics = $career['metrics'])
                    <tr wire:key="table-{{ $career['career']->id }}" wire:click="openCareer('{{ $career['career']->id }}')" class="cursor-pointer hover:bg-base-200/60" data-testid="table-{{ $career['career']->code }}">
                        <th scope="row" class="px-4 py-2.5 text-start font-normal">
                            <button type="button" wire:click.stop="openCareer('{{ $career['career']->id }}')" class="grid gap-px text-start" aria-label="Abrir las materias de {{ $career['career']->name }}">
                                <span class="font-mono font-semibold">{{ $career['career']->code }}</span>
                                <span class="font-semibold">{{ $career['career']->name }}</span>
                            </button>
                        </th>
                        <td class="px-3 py-2.5 text-end"><x-academic-load::percent :value="$metrics->weightedError" class="font-semibold" /></td>
                        <td class="px-3 py-2.5 text-end"><x-academic-load::percent :value="$metrics->baselineError" /></td>
                        <td class="px-3 py-2.5 text-end font-mono tabular-nums">{{ $metrics->improvement() === null ? '—' : number_format($metrics->improvement() * 100, 1) }}</td>
                        <td class="px-3 py-2.5 text-end"><x-academic-load::percent :value="$metrics->withinShare" :digits="0" /></td>
                        <td class="px-3 py-2.5 text-end font-mono tabular-nums">@include('academic-load::forecast-accuracy.bias', ['bias' => $metrics->bias])</td>
                        <td class="px-4 py-2.5"><x-core::status-badge :tone="$metrics->confidence->tone()" :label="$metrics->confidence->label()" :icon="$metrics->confidence->icon()" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <ul class="divide-y divide-base-300 lg:hidden">
        @foreach ($report->careers as $career)
            @php($metrics = $career['metrics'])
            <li wire:key="stack-{{ $career['career']->id }}">
                <button type="button" wire:click="openCareer('{{ $career['career']->id }}')" class="grid w-full gap-1.5 px-3.5 py-3 text-start hover:bg-base-200/60">
                    <span class="flex items-center gap-2">
                        <span class="font-mono font-semibold">{{ $career['career']->code }}</span>
                        <span class="grow"></span>
                        <x-core::status-badge :tone="$metrics->confidence->tone()" :label="$metrics->confidence->label()" :icon="$metrics->confidence->icon()" />
                    </span>
                    <span class="font-semibold">{{ $career['career']->name }}</span>
                    <span class="flex flex-wrap gap-x-4 gap-y-1">
                        @foreach ([['Error', $metrics->weightedError, 1], ['R1', $metrics->baselineError, 1], ['Dentro del 15 %', $metrics->withinShare, 0]] as [$label, $value, $digits])
                            <span class="flex flex-col"><span class="text-xs text-neutral">{{ $label }}</span><x-academic-load::percent :value="$value" :digits="$digits" class="font-semibold" /></span>
                        @endforeach
                        <span class="flex flex-col"><span class="text-xs text-neutral">Sesgo</span><span class="font-mono font-semibold tabular-nums">@include('academic-load::forecast-accuracy.bias', ['bias' => $metrics->bias])</span></span>
                    </span>
                </button>
            </li>
        @endforeach
    </ul>
</section>
