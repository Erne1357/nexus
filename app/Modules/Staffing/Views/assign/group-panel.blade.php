{{--
    El grupo elegido en los dos paneles: encabezado con su clave y su materia, sus datos y su docente; las pestañas
    «Ranking» e «Historial» y, a su derecha, «Siguiente sin docente», que elige el que sigue en la lista. Debajo de lg
    es una hoja a pantalla completa (entra desde la derecha) con «Grupos» para volver; Esc también la cierra.
--}}
@php($group = $row->group)
@php($ranking = $rankings[$group->id] ?? null)
@php($groupWritable = $canAssign && in_array((string) $group->campus_id, $assignableCampuses, true))
@php($readOnlyReason = $groupWritable || ! $canAssign ? $readOnlyReason : ($campusReadOnly ?? $readOnlyReason))
@php($canAssign = $groupWritable)
<section aria-labelledby="group-title" data-testid="group-panel" data-selected-group="{{ $group->id }}"
    @if ($sheet) x-on:keydown.escape.window="if (window.innerWidth < 1024) { $wire.closeSheet() }" @endif
    @class([
        'min-w-0 rounded-box border border-base-300 bg-base-100',
        'max-lg:fixed max-lg:inset-0 max-lg:z-50 max-lg:overflow-y-auto max-lg:overscroll-contain max-lg:rounded-none max-lg:border-0 max-lg:transition-transform max-lg:duration-500 max-lg:ease-(--ease-open) max-lg:starting:translate-x-full motion-reduce:transition-none' => $sheet,
        'max-lg:hidden' => ! $sheet,
    ])>
    <header class="space-y-3 border-b border-base-300 px-4 py-3.5 sm:px-5 max-lg:sticky max-lg:top-0 max-lg:z-10 max-lg:bg-base-100">
        <button type="button" wire:click="closeSheet" class="btn btn-ghost btn-sm -ms-2 lg:hidden max-md:h-11" data-testid="sheet-back"><x-icon name="o-chevron-left" class="h-4 w-4" />Grupos</button>

        <div class="flex flex-wrap items-start justify-between gap-x-5 gap-y-3">
            <div class="min-w-0">
                <h2 id="group-title" tabindex="-1" class="text-xl font-bold leading-tight focus:outline-none" data-testid="group-title"><span class="font-mono">{{ $group->code }}</span> · {{ $group->subject?->name }}</h2>
                <p class="mt-1.5 flex flex-wrap gap-x-3.5 gap-y-1 text-sm text-neutral">
                    <span class="font-mono font-semibold text-base-content">{{ $group->subject?->code }}</span>
                    <span><span class="font-mono">{{ $group->ownerCareer?->code }}</span> · semestre {{ $group->semester }}</span>
                    <span class="font-mono">{{ $row->schedule }}</span>
                    <span><span class="font-mono tabular-nums">{{ $row->hours }}</span> h por semana</span>
                    @if ($group->campus !== null)
                        <span class="inline-flex items-center gap-1.5">@if ($multiCampus)<x-core::campus-tag :campus="$group->campus" decorative />@endif{{ $group->campus->name }}</span>
                    @endif
                </p>
            </div>
            <div class="grid justify-items-start gap-1.5 text-sm md:justify-items-end md:text-right">
                @if ($row->hasTeacher())
                    <x-core::status-badge tone="success" label="Con docente" />
                    <span class="font-semibold">{{ $group->teacher?->name }}</span>
                    @if ($row->assignment !== null)
                        <span class="text-neutral">
                            @if ($row->assignment->ranking_position_at_assignment === null)
                                Fuera del ranking
                            @else
                                Lugar <span class="font-mono tabular-nums">{{ $row->assignment->ranking_position_at_assignment }}</span> de <span class="font-mono tabular-nums">{{ $row->assignment->ranking_size_at_assignment }}</span>
                            @endif
                            · {{ $row->assignment->assignedBy?->name }}
                        </span>
                    @endif
                @else
                    <x-core::status-badge tone="warning" label="Sin docente" icon="o-clock" />
                @endif
            </div>
        </div>
    </header>

    <div class="flex items-center gap-2 pe-2">
        <div role="tablist" aria-label="Grupo {{ $group->code }}" class="tabs tabs-border relative min-w-0 flex-1 flex-nowrap overflow-x-auto px-2">
            <button type="button" role="tab" id="tab-ranking" aria-controls="panel-group" aria-selected="{{ $tab === 'ranking' ? 'true' : 'false' }}" wire:click="setTab('ranking')"
                @class(['tab gap-1.5 whitespace-nowrap max-md:h-11', 'tab-active' => $tab === 'ranking']) data-testid="tab-ranking">Ranking <span class="badge badge-sm badge-neutral badge-soft font-mono">{{ $row->rankingSize }}</span></button>
            <button type="button" role="tab" id="tab-history" aria-controls="panel-group" aria-selected="{{ $tab === 'historial' ? 'true' : 'false' }}" wire:click="setTab('historial')"
                @class(['tab gap-1.5 whitespace-nowrap max-md:h-11', 'tab-active' => $tab === 'historial']) data-testid="tab-history">Historial <span class="badge badge-sm badge-neutral badge-soft font-mono">{{ count($ranking?->history ?? []) }}</span></button>
        </div>
        @include('staffing::assign.next-button', ['from' => $group->id, 'next' => $nextWithoutTeacher[$group->id] ?? null, 'pick' => true])
    </div>

    <div role="tabpanel" id="panel-group" aria-labelledby="{{ $tab === 'historial' ? 'tab-history' : 'tab-ranking' }}" class="border-t border-base-300">
        @if ($tab === 'historial')
            @include('staffing::assign.history', ['group' => $group, 'history' => $ranking?->history ?? []])
        @elseif ($row->rankingState === \App\Modules\Staffing\Enums\RankingState::Pending)
            <p class="flex items-center justify-center gap-2 px-4 py-8 text-sm text-neutral" data-testid="ranking-pending">
                <span class="loading loading-spinner loading-xs" aria-hidden="true"></span>Calculando el ranking…
            </p>
        @elseif ($ranking !== null)
            @include('staffing::assign.ranking', ['group' => $group, 'ranking' => $ranking, 'currentTeacherId' => $group->teacher_id])
        @endif
    </div>
</section>
