{{--
    Una materia en un campus (opción B «Por materia»): la clave en Mono, la etiqueta del campus y el nombre; lo esperado y
    los cupos en N grupos; lo que el jefe ya ajustó desde la versión vigente («Ya ajustaste E: grupo nuevo», en info); y,
    a quien ve toda la carga, «Sugerencias», que abre el drawer de la tarea 8.3. Debajo, un renglón por carrera
    (reanalysis.row). Si todas sus carreras están dentro del umbral o son de primer semestre (RA-09) y nada se marca ni
    se tocó, va compacta: una pastilla por carrera («ISIC 84/90», «ISIC · Nuevo ingreso: 120»). `$block`, `$board`,
    `$withSuggestions` y `$mine` (si quien mira es quien ajusta: «Ya ajustaste» o «Ya se ajustó»).
--}}
@php($titleId = 'block-'.\Illuminate\Support\Str::slug($block->subjectCode.'-'.($block->campus?->code ?? 'sin-campus')))
<li @class(['grid border-t border-base-300 first:border-t-0', 'gap-1 px-3.5 py-2.5 md:px-[18px]' => $block->compact, 'gap-2.5 px-3.5 pt-3.5 pb-4 md:px-[18px]' => ! $block->compact]) aria-labelledby="{{ $titleId }}" data-testid="reanalysis-block" data-subject="{{ $block->subjectCode }}" data-campus="{{ $block->campus?->code }}" @if ($block->compact) data-compact @endif>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
        <div class="flex min-w-0 items-center gap-2">
            <span class="font-mono text-[15px] font-semibold tabular-nums">{{ $block->subjectCode }}</span>
            <x-core::campus-tag :campus="$block->campus" />
            <h3 id="{{ $titleId }}" @class(['min-w-0', 'text-[15px] font-semibold' => $block->compact, 'text-base font-bold' => ! $block->compact])>{{ $block->subjectName }}</h3>
        </div>
        <p class="flex min-w-0 flex-[1_1_auto] flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[13.5px] text-neutral">
            <span><b class="font-mono font-semibold tabular-nums text-base-content">{{ number_format($block->expected) }}</b> esperados</span>
            <span aria-hidden="true">·</span>
            <span><b class="font-mono font-semibold tabular-nums text-base-content">{{ number_format($block->seats) }}</b> cupos en <b class="font-mono font-semibold tabular-nums text-base-content">{{ $block->groups }}</b> {{ $block->groups === 1 ? 'grupo' : 'grupos' }}</span>
            @if ($block->touched !== [])
                <span class="flex min-w-0 basis-full items-start gap-1 text-info md:ms-2 md:basis-auto" data-testid="block-touched"><x-icon name="o-pencil-square" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span>{{ $mine ? 'Ya ajustaste' : 'Ya se ajustó' }} {{ implode('; ', $block->touched) }}</span></span>
            @endif
        </p>
        @if ($withSuggestions)
            <x-button
                label="Sugerencias"
                icon="o-light-bulb"
                class="btn-outline btn-sm max-md:h-11 max-md:w-full md:ms-auto"
                wire:click="$dispatch('open-reanalysis-suggestions', { subjectId: {{ \Illuminate\Support\Js::from($block->subjectId) }}, campusId: {{ \Illuminate\Support\Js::from($block->campus?->id) }} })"
                aria-label="Sugerencias para {{ $block->subjectCode }} {{ $block->subjectName }}{{ $block->campus ? ' en '.$block->campus->name : '' }}"
                data-testid="block-suggestions"
            />
        @endif
    </div>

    @if ($block->compact)
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1.5">
            @foreach ($block->rows as $row)
                @php($first = $row->balance === null)
                <span class="inline-flex items-center gap-1.5 rounded-[4px] border border-base-300 bg-base-200 px-2 py-0.5 text-[13px]" data-testid="compact-career" data-career="{{ $row->careerCode }}">
                    <x-icon :name="$first ? 'o-user-plus' : 'o-check-circle'" @class(['h-3.5 w-3.5', 'text-neutral' => $first, 'text-success' => ! $first]) aria-hidden="true" />
                    <span class="font-mono text-[12.5px] font-semibold">{{ $row->careerCode }}</span>
                    @if ($first)
                        <span>Nuevo ingreso: <span class="font-mono tabular-nums">{{ number_format(\App\Modules\Scheduling\Queries\ReanalysisBoard::shownStudents($row)) }}</span></span>
                    @else
                        <span class="font-mono tabular-nums" aria-hidden="true">{{ number_format($row->expectedStudents) }}/{{ number_format($row->offeredSeats) }}</span>
                        <span class="sr-only">{{ $row->expectedStudents }} esperados y {{ $row->offeredSeats }} cupos: ajustada</span>
                    @endif
                </span>
            @endforeach
            <span class="text-[13px] text-neutral">{{ $block->onlyFirstSemester() ? 'Primer semestre: no se clasifica' : 'Dentro del '.$board->threshold.' %' }}</span>
        </p>
    @else
        <ul class="grid rounded-box border border-base-300">
            @foreach ($block->rows as $row)
                @include('scheduling::reanalysis.row', ['row' => $row, 'block' => $block, 'board' => $board])
            @endforeach
        </ul>
    @endif
</li>
