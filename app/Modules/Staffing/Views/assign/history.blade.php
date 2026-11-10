{{--
    El historial de asignaciones de un grupo (HC-16), del más reciente al más antiguo: cuándo y quién, a qué docente, el
    lugar que ocupaba («3 de 12» o «Fuera del ranking»), el motivo y si sigue vigente, fue reemplazada o se quitó. Con la
    caja de 44rem o más es una tabla de cinco columnas; debajo, cada asignación se apila.
--}}
@php($historyGrid = '@[44rem]:grid-cols-[9.25rem_minmax(0,1fr)_6.5rem_minmax(0,1.6fr)_8rem]')
<div class="@container" data-testid="history">
    @if ($history === [])
        <p class="px-4 py-6 text-center text-sm text-neutral">Este grupo todavía no tiene asignaciones.</p>
    @else
        <div role="table" aria-label="Historial de asignaciones de {{ $group->code }}">
            <div role="rowgroup">
                <div role="row" @class(['hidden gap-x-3 border-y border-base-300 bg-secondary/40 px-4 py-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted @[44rem]:grid', $historyGrid])>
                    <span role="columnheader">Cuándo y quién</span>
                    <span role="columnheader">Docente</span>
                    <span role="columnheader">Lugar</span>
                    <span role="columnheader">Motivo</span>
                    <span role="columnheader">Estado</span>
                </div>
            </div>
            <div role="rowgroup" class="divide-y divide-base-300 @[44rem]:border-b-0">
                @foreach ($history as $entry)
                    @php($assignment = $entry['assignment'])
                    @php($active = $entry['status'] === 'vigente')
                    <div role="row" wire:key="history-{{ $assignment->id }}" @class(['grid gap-x-3 gap-y-1 px-4 py-3 text-sm', $historyGrid, 'text-secondary-muted' => ! $active]) data-testid="history-row">
                        <span role="cell" class="grid">
                            <span class="font-mono text-[12.5px] tabular-nums">{{ $assignment->assigned_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                            <span class="text-[12.5px] text-neutral">{{ $assignment->assignedBy?->name }}</span>
                        </span>
                        <span role="cell" @class(['min-w-0', 'font-semibold' => $active])>{{ $assignment->teacher?->name }}</span>
                        <span role="cell">
                            @if ($assignment->ranking_position_at_assignment === null)
                                Fuera del ranking
                            @else
                                <span class="font-mono tabular-nums">{{ $assignment->ranking_position_at_assignment }} de {{ $assignment->ranking_size_at_assignment }}</span>
                            @endif
                        </span>
                        <span role="cell" @class(['min-w-0 break-words', 'text-neutral' => $assignment->reason === null])>{{ $assignment->reason ?? 'Primer lugar: no requiere motivo.' }}</span>
                        <span role="cell" class="grid content-start justify-items-start gap-1">
                            @if ($active)
                                <x-core::status-badge tone="success" label="Vigente" />
                            @elseif ($entry['status'] === 'reemplazada')
                                <x-core::status-badge tone="neutral" label="Reemplazada" icon="o-arrow-path" />
                            @else
                                <x-core::status-badge tone="draft" label="Quitada" icon="o-minus-circle" />
                            @endif
                            @if (! $active && $assignment->replaced_at !== null)
                                <span class="text-[12.5px] text-neutral">{{ $assignment->replacedBy?->name }} · <span class="font-mono tabular-nums">{{ $assignment->replaced_at->setTimezone(config('app.timezone'))->format('d/m/Y') }}</span></span>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
