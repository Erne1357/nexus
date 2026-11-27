{{--
    El docente vigente de un grupo, en un renglón: la insignia «Con docente» (la única señal verde), su nombre y su
    plaza, el lugar que ocupaba en el ranking al asignarlo (o «Fuera del ranking») y quién lo asignó y cuándo. Un docente
    puesto sin asignación (otra vía) solo lleva su nombre.
--}}
@php($assignment = $row->assignment)
<div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1" data-testid="assigned-card">
    <x-core::status-badge tone="success" label="Con docente" />
    <span class="font-semibold">{{ $row->group->teacher?->name }}</span>
    @if ($row->teacherPositions !== null)
        <span class="badge badge-sm badge-neutral badge-soft whitespace-nowrap font-mono font-semibold">{{ $row->teacherPositions }}</span>
    @endif
    @if ($assignment !== null)
        <span class="text-sm">
            @if ($assignment->ranking_position_at_assignment === null)
                Fuera del ranking
            @else
                Lugar <span class="font-mono font-semibold tabular-nums">{{ $assignment->ranking_position_at_assignment }}</span> de <span class="font-mono tabular-nums">{{ $assignment->ranking_size_at_assignment }}</span>
            @endif
        </span>
        <span class="text-[12.5px] text-neutral">Asignó {{ $assignment->assignedBy?->name }} · <span class="font-mono tabular-nums">{{ $assignment->assigned_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i') }}</span></span>
    @endif
</div>
