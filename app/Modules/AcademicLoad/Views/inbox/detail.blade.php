{{--
    El detalle de la carrera elegida (academic-load::pages.inbox): su estado y sus botones, un aviso si otro
    departamento regresó la propuesta mientras esta parte sigue pendiente, el comentario si la regresaron, y las
    materias que pide al departamento con los grupos contra el total de la materia y la sugerencia de paralelos.
--}}
@php($career = $selected['career'])
@php($review = $selected['review'])
<div class="space-y-4 p-4 sm:p-5">
    <button type="button" wire:click="back" class="btn btn-sm lg:hidden" data-testid="inbox-back"><x-icon name="o-chevron-left" class="h-4 w-4" />Carreras</button>

    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div class="min-w-0">
            <h2 class="text-xl font-bold leading-tight"><span class="font-mono">{{ $career->code }}</span> · {{ $career->name }}</h2>
            <p class="mt-1 text-sm text-neutral">
                @if ($review === null)
                    @if ($selected['proposal'] === null || $selected['proposal']->status === \App\Modules\AcademicLoad\Enums\ProposalStatus::Draft)
                        Todavía no envía su propuesta.
                    @else
                        No le pide materias a {{ $inbox->department->name }}.
                    @endif
                @else
                    {{ $selected['groups'] }} grupos · {{ $selected['expected'] }} esperados · {{ $selected['subjects'] }} {{ $selected['subjects'] === 1 ? 'materia' : 'materias' }}
                @endif
            </p>
            <x-core::status-badge class="mt-2" :tone="$selected['status']->tone()" :label="$selected['status']->label()" data-testid="career-status" />
        </div>

        @if ($canDecide)
            <div class="flex flex-wrap gap-2">
                <span @if ($acceptBlock !== null) class="tooltip" data-tip="{{ $acceptBlock }}" @endif>
                    <x-button label="Aceptar" icon="o-check" class="btn-primary" wire:click="confirmAccept('{{ $review->getKey() }}')" spinner="confirmAccept"
                        :disabled="$acceptBlock !== null" data-testid="accept-part" />
                </span>
                <x-button label="Regresar" icon="o-arrow-uturn-left" class="btn-outline" wire:click="confirmReturn('{{ $review->getKey() }}')" spinner="confirmReturn" data-testid="return-part" />
            </div>
        @endif
    </div>

    @if ($acceptBlock !== null && $canDecide)
        <p class="text-sm text-neutral" data-testid="accept-block">{{ $acceptBlock }}</p>
    @endif

    @if ($selected['decidable'] && $selected['proposal']->status === \App\Modules\AcademicLoad\Enums\ProposalStatus::Returned)
        <p class="text-sm text-neutral" data-testid="proposal-in-correction">Otro departamento la regresó; el coordinador la está corrigiendo.</p>
    @endif

    @if ($review?->comment)
        <div class="rounded-box border-l-4 border-error bg-error/10 px-4 py-3 text-sm" data-testid="part-comment">
            {{ $review->comment }}
            @if ($review->decided_at)
                <span class="mt-1 block text-xs text-neutral">{{ $review->decided_at->format('d/m/Y') }}</span>
            @endif
        </div>
    @endif

    @if ($items === [])
        <p class="text-sm text-neutral">Sin materias de este departamento en la propuesta.</p>
    @else
        <div class="grid grid-cols-[minmax(0,1fr)_auto_auto] gap-x-4 border-t border-base-300 pt-3 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral" aria-hidden="true">
            <span>Materia</span><span class="text-right">Grupos</span><span class="w-[4.75rem] text-right">Esperados</span>
        </div>
        <ul class="-mt-1 divide-y divide-base-300 border-y border-base-300" data-testid="inbox-items">
            @foreach ($items as $entry)
                @php($item = $entry['item'])
                @php($total = $entry['total'])
                <li wire:key="item-{{ $item->getKey() }}" class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-x-4 gap-y-1 py-3" data-item="{{ $item->subject->code }}">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $item->subject->name }}</p>
                        <p class="font-mono text-xs text-neutral">{{ $item->subject->code }} · semestre {{ $item->semester }}</p>
                    </div>
                    <span class="text-right font-mono text-lg font-semibold tabular-nums" title="Grupos">{{ $item->requested_groups }}</span>
                    <span class="w-[4.75rem] text-right font-mono tabular-nums text-neutral" title="Esperados">{{ $item->expected_students }}</span>
                    <div class="col-span-3 space-y-1">
                        @if ($total['groups'] >= $item->requested_groups && $total['groups'] > 0)
                            <p class="text-xs"><span class="font-mono font-semibold">{{ $item->requested_groups }}</span> de {{ $total['groups'] }} grupos</p>
                            <progress class="progress progress-primary h-1.5 w-full" value="{{ $item->requested_groups }}" max="{{ $total['groups'] }}"></progress>
                        @endif
                        @if (isset($suggestions[$item->subject_id]))
                            <p class="text-xs text-neutral" data-testid="parallels">{{ $suggestions[$item->subject_id] }}</p>
                        @endif
                        <p class="text-xs text-neutral">
                            {{ $item->eligible_students }} {{ $item->eligible_students === 1 ? 'elegible' : 'elegibles' }}
                            @if ($item->notes)
                                · {{ $item->notes }}
                            @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="flex items-baseline justify-between gap-4 px-0 text-sm font-semibold">
            <span>Total</span>
            <span class="font-mono tabular-nums">{{ $selected['groups'] }} grupos · {{ $selected['expected'] }} esperados</span>
        </div>
    @endif
</div>
