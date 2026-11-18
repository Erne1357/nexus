{{--
    Debajo de 768 px, la matriz de «Demanda por materia» en renglones apilados (maqueta C a 390 px): por materia, su
    clave y su total de todo el Tec con el reparto por campus, el nombre y una etiqueta por cada carrera que la lleva en
    el campus del filtro, con su celda (resaltada o punteada como en la matriz). Cada renglón abre el drawer.
--}}
@php($lensId = $board->career?->getKey())
<ul class="divide-y divide-base-300 md:hidden" data-testid="demand-cards">
    @foreach ($visible as $row)
        @php($subject = $row->subject)
        @php($total = $row->total($campusId))
        <li wire:key="demand-card-{{ $subject->id }}">
            <button type="button" wire:click="open(@js($subject->code))" class="flex w-full flex-col gap-1.5 px-4 py-3 text-start hover:bg-base-200/50" data-testid="demand-card-{{ $subject->code }}">
                <span class="flex items-start justify-between gap-3">
                    <span class="pt-0.5 font-mono text-[15px] font-semibold">{{ $subject->code }}</span>
                    <span @class(['flex flex-col items-end text-[13.5px]', 'text-warning' => $total->large])>
                        <span>@include('academic-load::demand.cell', ['cell' => $total, 'label' => 'Todo el Tec'])</span>
                        @if ($shownCampus === null && count($board->campuses) > 1)
                            <span class="flex gap-1.5 text-[11.5px] text-neutral" aria-hidden="true">
                                @foreach ($row->byCampus() as $split)
                                    <span class="inline-flex items-center gap-1"><x-core::campus-tag :campus="$split['campus']" size="xs" decorative /><span class="font-mono tabular-nums">{{ $split['cell']->counted }}/{{ $split['cell']->suggested }}</span></span>
                                @endforeach
                            </span>
                        @endif
                    </span>
                </span>
                <span class="font-medium leading-snug">{{ $subject->name }}@if ($subject->needs_lab)<x-icon name="o-beaker" class="ms-1 inline h-4 w-4 align-[-3px] text-neutral" aria-hidden="true" />@endif</span>
                @if ($board->isCareerLens())
                    <span class="-mt-1 text-xs text-neutral"><span class="font-mono">{{ $subject->department?->code }}</span>@if ($row->semester !== null) · semestre {{ $row->semester }}@endif</span>
                @endif
                <span class="flex flex-wrap gap-1.5">
                    @foreach ($board->careers as $column)
                        @php($cell = $row->cell($column['career']->getKey(), $campusId))
                        @if ($cell !== null)
                            <span @class([
                                'inline-flex items-center gap-1.5 rounded border px-2 py-0.5 text-[13px]',
                                'border-warning/40 bg-warning/12 text-warning' => $cell->large,
                                'border-dashed border-base-content/35 text-neutral' => $cell->pending,
                                'border-base-300' => ! $cell->large && ! $cell->pending,
                                'bg-info/8' => $column['career']->getKey() === $lensId && ! $cell->large,
                            ])><span class="font-mono text-xs font-bold text-base-content" aria-hidden="true">{{ $column['career']->code }}</span>@include('academic-load::demand.cell', ['cell' => $cell, 'label' => $column['career']->code, 'bare' => true])</span>
                        @endif
                    @endforeach
                </span>
            </button>
        </li>
    @endforeach
</ul>
