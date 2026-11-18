{{--
    La matriz materias × carreras de «Demanda por materia», desde 768 px. La columna de la materia queda fija a la
    izquierda y «Todo el Tec» a la derecha al desplazar la matriz de lado dentro de su caja (la página nunca desborda);
    sus fondos son opacos y repiten el del renglón. El encabezado de cada carrera dice el estado de su propuesta con su
    icono; en la lente de carrera, la suya va primero con un filete `primary` y su columna en un tinte `info`. Cada celda
    resaltada va en `warning` con su icono de tendencia. Al pie, la suma de lo que se ve. Tocar un renglón abre su drawer.
--}}
@php($lensId = $board->career?->getKey())
@php($stickyBody = 'bg-base-100 group-hover:bg-[color-mix(in_oklab,var(--color-base-200)_50%,var(--color-base-100))]')
@php($stickyOpen = 'bg-[color-mix(in_oklab,var(--color-secondary)_40%,var(--color-base-100))]')
<div class="relative hidden overflow-x-auto md:block">
    <table class="w-full border-separate border-spacing-0 text-sm" data-testid="demand-table">
        <caption class="sr-only">Grupos pedidos contra sugeridos por materia y carrera{{ $shownCampus ? ' en '.$shownCampus->name : '' }}</caption>
        <thead>
            <tr>
                <th scope="col" class="sticky left-0 z-10 min-w-56 max-w-72 border-e border-base-300 bg-base-200 px-4 py-2.5 text-start align-bottom text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Materia</th>
                @foreach ($board->careers as $column)
                    @php($mine = $column['career']->getKey() === $lensId)
                    <th scope="col" title="{{ $column['career']->name }} · {{ $column['state']->label() }}"
                        @class([
                            'min-w-20 px-2.5 py-2 text-end align-bottom font-normal',
                            'bg-base-200' => ! $mine,
                            'bg-[color-mix(in_oklab,var(--color-info)_10%,var(--color-base-200))] shadow-[inset_0_-3px_0_0_var(--color-primary)]' => $mine,
                        ]) data-testid="demand-column-{{ $column['career']->code }}">
                        <span class="block font-mono text-[13.5px] font-bold text-base-content">{{ $column['career']->code }}</span>
                        @include('academic-load::demand.state', ['state' => $column['state'], 'short' => true])
                    </th>
                @endforeach
                <th scope="col" class="sticky right-0 z-10 min-w-32 border-s border-base-300 bg-base-200 px-3 py-2.5 text-end align-bottom font-semibold">Todo el Tec</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($visible as $row)
                @php($subject = $row->subject)
                @php($open = $drawer !== null && $drawer->subject->getKey() === $subject->getKey())
                @php($total = $row->total($campusId))
                <tr wire:key="demand-row-{{ $subject->id }}" wire:click="open(@js($subject->code))" class="group cursor-pointer" data-testid="demand-row-{{ $subject->code }}">
                    <th scope="row" @class(['sticky left-0 z-10 min-w-56 max-w-72 border-e border-t border-base-300 px-4 py-2 text-start align-middle font-normal', $stickyBody => ! $open, $stickyOpen => $open])>
                        <button type="button" wire:click.stop="open(@js($subject->code))" class="group/name block text-start" aria-label="Abrir el detalle de {{ $subject->code }} {{ $subject->name }}">
                            <span class="block font-mono text-[15px] font-semibold">{{ $subject->code }}</span>
                            <span class="leading-snug underline decoration-transparent underline-offset-4 group-hover/name:decoration-current group-focus-visible/name:decoration-current">{{ $subject->name }}</span>
                            @if ($subject->needs_lab)
                                <x-icon name="o-beaker" class="ms-1 inline h-4 w-4 align-[-3px] text-neutral" aria-hidden="true" /><span class="sr-only">, requiere laboratorio</span>
                            @endif
                        </button>
                        @if ($board->isCareerLens())
                            <span class="block text-xs text-neutral"><span class="font-mono">{{ $subject->department?->code }}</span>@if ($row->semester !== null) · semestre {{ $row->semester }}@endif</span>
                        @endif
                    </th>
                    @foreach ($board->careers as $column)
                        @php($cell = $row->cell($column['career']->getKey(), $campusId))
                        @php($mine = $column['career']->getKey() === $lensId)
                        <td @class([
                            'border-t border-base-300 px-2.5 py-2 text-end align-middle whitespace-nowrap',
                            'bg-warning/12 text-warning' => $cell?->large,
                            'bg-info/8' => $mine && ! $cell?->large,
                            'group-hover:bg-base-200/50' => ! $mine && ! $cell?->large && ! $open,
                            'bg-secondary/40' => $open && ! $mine && ! $cell?->large,
                        ]) data-testid="demand-cell-{{ $subject->code }}-{{ $column['career']->code }}" @if ($cell?->large) data-large @endif @if ($cell?->pending) data-pending @endif>
                            @if ($cell !== null)
                                @include('academic-load::demand.cell', ['cell' => $cell, 'label' => $column['career']->code])
                            @else
                                <span class="sr-only">{{ $column['career']->code }} no la lleva{{ $shownCampus ? ' en '.$shownCampus->code : '' }}.</span>
                            @endif
                        </td>
                    @endforeach
                    <td @class([
                        'sticky right-0 z-10 min-w-32 border-s border-t border-base-300 px-3 py-2 text-end align-middle',
                        'bg-[color-mix(in_oklab,var(--color-warning)_12%,var(--color-base-200))] text-warning' => $total->large,
                        'bg-base-200' => ! $total->large,
                    ]) data-testid="demand-total-{{ $subject->code }}" @if ($total->large) data-large @endif>
                        @include('academic-load::demand.cell', ['cell' => $total, 'label' => 'Todo el Tec'])
                        @if ($shownCampus === null && count($board->campuses) > 1)
                            <span class="mt-0.5 flex justify-end gap-2 text-[11.5px] text-neutral">
                                @foreach ($row->byCampus() as $split)
                                    <span class="inline-flex items-center gap-1"><x-core::campus-tag :campus="$split['campus']" size="xs" /><span class="font-mono tabular-nums" aria-hidden="true">{{ $split['cell']->counted }}/{{ $split['cell']->suggested }}</span><span class="sr-only">{{ $split['cell']->counted }} pedidos de {{ $split['cell']->suggested }}</span></span>
                                @endforeach
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot data-testid="demand-footer">
            <tr class="font-semibold">
                <th scope="row" class="sticky left-0 z-10 border-e border-t border-base-300 bg-base-200 px-4 py-2.5 text-start">Todo el Tec · <span class="font-mono tabular-nums">{{ count($visible) }}</span> {{ count($visible) === 1 ? 'materia' : 'materias' }}</th>
                @foreach ($board->careers as $column)
                    @php($sum = $footer['careers'][$column['career']->getKey()])
                    <td class="border-t border-base-300 bg-base-200 px-2.5 py-2.5 text-end whitespace-nowrap">
                        <span aria-hidden="true"><span class="font-mono tabular-nums">{{ $sum->counted }}</span><span class="font-mono font-normal tabular-nums text-neutral">/{{ $sum->suggested }}</span></span>
                        <span class="sr-only">{{ $column['career']->code }}: {{ $sum->counted }} de {{ $sum->suggested }}</span>
                    </td>
                @endforeach
                <td class="sticky right-0 z-10 border-s border-t border-base-300 bg-base-200 px-3 py-2.5 text-end whitespace-nowrap">
                    <span aria-hidden="true"><span class="font-mono tabular-nums">{{ $footer['total']->counted }}</span><span class="font-mono font-normal tabular-nums text-neutral">/{{ $footer['total']->suggested }}</span></span>
                    <span class="sr-only">Todo el Tec: {{ $footer['total']->counted }} de {{ $footer['total']->suggested }}</span>
                </td>
            </tr>
        </tfoot>
    </table>
</div>
