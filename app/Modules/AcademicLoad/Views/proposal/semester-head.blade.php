{{--
    El encabezado de un semestre en la tabla de la propuesta (academic-load::proposal.table), en las dos formas: sus
    grupos, los de cada campus si hay más de uno («C1 32 · C2 9 grupos»; ProposalOverview::groupsByColumn). `$rows` son
    sus ProposalSubject.
--}}
@php
    $byColumn = $overview->groupsByColumn($rows);
    $total = array_sum($byColumn);
@endphp
<div class="flex items-center justify-between gap-2 border-b border-base-300 bg-base-200 px-4 py-2">
    <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Semestre {{ $semester }}</h3>
    <span class="font-mono text-sm text-neutral" data-testid="semester-groups-{{ $semester }}">
        @if (count($columns) > 1)
            {{ implode(' · ', array_map(fn ($key) => ($columns[$key]['campus']?->code ?? '—').' '.$byColumn[$key], array_keys($columns))) }} {{ $total === 1 ? 'grupo' : 'grupos' }}
        @else
            {{ $total }} {{ $total === 1 ? 'grupo' : 'grupos' }}
        @endif
    </span>
</div>
