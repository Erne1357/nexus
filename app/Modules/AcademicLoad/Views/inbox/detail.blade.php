{{--
    El detalle de la carrera elegida (academic-load::pages.inbox), como en la maqueta C pero con una tabla compacta
    para el volumen (~48 px por materia): el encabezado fijo con la carrera, su estado y los botones; un aviso si otro
    departamento regresó la propuesta; el comentario si la regresaron; una fila por materia con sus elegibles,
    esperados, grupos y el frente al total de la materia (con el chip de paralelos donde ahorra o hay mixtos); y el
    total fijo abajo. Con la caja de menos de 42rem (consulta de contenedor), cada materia ocupa dos renglones.
--}}
@php($career = $selected['career'])
@php($review = $selected['review'])
@php($rowGrid = '@2xl:grid-cols-[minmax(0,1fr)_4.75rem_4.75rem_3.75rem_minmax(9rem,12rem)_2.25rem]')
<div class="@container" data-testid="inbox-detail-body">
    <div class="sticky top-(--shell-top) z-20 space-y-3 rounded-t-box border-b border-base-300 bg-base-100 px-4 py-3 sm:px-5" data-testid="inbox-detail-header">
        <button type="button" wire:click="back" class="btn btn-sm lg:hidden max-md:h-11 max-md:min-h-11" data-testid="inbox-back"><x-icon name="o-chevron-left" class="h-4 w-4" />Carreras</button>

        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
            <div class="min-w-0">
                <h2 class="text-xl font-bold leading-tight" tabindex="-1" data-testid="inbox-detail-title"><span class="font-mono">{{ $career->code }}</span> · {{ $career->name }}</h2>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <x-core::status-badge :tone="$selected['status']->tone()" :label="$selected['status']->label()" data-testid="career-status" />
                    <p class="text-sm text-neutral">
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
                </div>
            </div>

            @if ($canDecide)
                <div class="flex flex-wrap gap-2">
                    <span @if ($acceptBlock !== null) class="tooltip" data-tip="{{ $acceptBlock }}" @endif>
                        <x-button label="Aceptar" icon="o-check" class="btn-primary max-md:h-11 max-md:min-h-11" wire:click="confirmAccept('{{ $review->getKey() }}')" spinner="confirmAccept"
                            :disabled="$acceptBlock !== null" data-opens="accept" data-testid="accept-part" />
                    </span>
                    <x-button label="Regresar" icon="o-arrow-uturn-left" class="btn-outline max-md:h-11 max-md:min-h-11" wire:click="confirmReturn('{{ $review->getKey() }}')" spinner="confirmReturn" data-opens="return" data-testid="return-part" />
                </div>
            @endif
        </div>

        @if ($acceptBlock !== null && $canDecide)
            <p class="text-sm text-neutral" data-testid="accept-block">{{ $acceptBlock }}</p>
        @endif
        @if ($items !== [])
            <div aria-hidden="true" @class(['hidden items-end gap-x-3 pt-1 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral @2xl:grid', $rowGrid])>
                <span>Materia</span>
                <span class="text-right">Elegibles</span>
                <span class="text-right">Esperados</span>
                <span class="text-right">Grupos</span>
                <span>Frente al total</span>
                <span></span>
            </div>
        @endif
    </div>

    <div class="space-y-4 px-4 py-4 sm:px-5">
        @if ($selected['decidable'] && $selected['proposal']->status === \App\Modules\AcademicLoad\Enums\ProposalStatus::Returned)
            <p class="text-sm text-neutral" data-testid="proposal-in-correction">Otro departamento la regresó; el coordinador la está corrigiendo.</p>
        @endif

        @if ($review?->comment)
            <div class="rounded-box bg-error/10 px-4 py-3 text-sm" data-testid="part-comment">
                <p class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Comentario de {{ $inbox->department->name }}</p>
                <p class="mt-1">{{ $review->comment }}</p>
                @if ($review->decided_at)
                    <p class="mt-1 text-neutral">{{ $review->decided_at->format('d/m/Y') }}</p>
                @endif
            </div>
        @endif

        @if ($items === [])
            <p class="text-sm text-neutral">Sin materias de este departamento en la propuesta.</p>
        @else
            <div role="table" aria-label="Materias que pide {{ $career->code }}" data-testid="inbox-items">
                <div role="rowgroup" class="sr-only">
                    <div role="row">
                        <span role="columnheader">Materia</span><span role="columnheader">Elegibles</span><span role="columnheader">Esperados</span><span role="columnheader">Grupos</span><span role="columnheader">Frente al total</span><span role="columnheader">Nota del coordinador</span>
                    </div>
                </div>
                <div role="rowgroup" class="divide-y divide-base-300">
                    @foreach ($items as $entry)
                        @php($item = $entry['item'])
                        @php($total = $entry['total'])
                        @php($chip = $chips[$item->subject_id] ?? null)
                        <div role="row" wire:key="item-{{ $item->getKey() }}" data-item="{{ $item->subject->code }}"
                            @class(['grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-x-3 gap-y-1 py-2 @2xl:min-h-12', $rowGrid])>
                            <div role="cell" class="min-w-0 @2xl:col-start-1 @2xl:row-start-1">
                                <p class="truncate text-sm font-semibold" title="{{ $item->subject->name }}">{{ $item->subject->name }}</p>
                                <p class="text-sm text-neutral"><span class="font-mono text-[15px] font-semibold text-base-content">{{ $item->subject->code }}</span> · <span class="whitespace-nowrap">semestre {{ $item->semester }}</span></p>
                            </div>
                            <span role="cell" class="hidden text-right font-mono text-[15px] font-semibold tabular-nums text-neutral @2xl:col-start-2 @2xl:row-start-1 @2xl:block" title="Elegibles">{{ $item->eligible_students }}</span>
                            <span role="cell" class="text-right font-mono text-[15px] font-semibold tabular-nums @2xl:col-start-3 @2xl:row-start-1" title="Esperados">{{ $item->expected_students }}<span class="text-sm font-normal text-neutral @2xl:sr-only"> esp.</span></span>
                            <span role="cell" class="text-right font-mono text-[15px] font-semibold tabular-nums @2xl:col-start-4 @2xl:row-start-1" title="Grupos">{{ $item->requested_groups }}<span class="text-sm font-normal text-neutral @2xl:sr-only"> {{ $item->requested_groups === 1 ? 'grupo' : 'grupos' }}</span></span>

                            <div role="cell" class="col-span-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm @2xl:col-span-1 @2xl:col-start-5 @2xl:row-start-1">
                                <span class="text-neutral @2xl:hidden">{{ $item->eligible_students }} {{ $item->eligible_students === 1 ? 'elegible' : 'elegibles' }}</span>
                                @if ($total['groups'] >= $item->requested_groups && $total['groups'] > 0)
                                    <span data-testid="against-total"><span class="font-mono font-semibold">{{ $item->requested_groups }}</span> de {{ $total['groups'] }} {{ $total['groups'] === 1 ? 'grupo' : 'grupos' }} · {{ $total['careers'] }} {{ $total['careers'] === 1 ? 'carrera' : 'carreras' }}</span>
                                @endif
                                @if ($chip !== null)
                                    <button type="button" wire:click="openParallels('{{ $item->subject_id }}')" data-opens="parallels-{{ $item->subject_id }}" data-testid="parallels-chip"
                                        class="btn btn-sm btn-soft btn-primary max-md:h-11 max-md:min-h-11">{{ $chip['label'] }}</button>
                                @endif
                            </div>

                            <div role="cell" class="col-span-3 text-sm @2xl:col-span-1 @2xl:col-start-6 @2xl:row-start-1 @2xl:flex @2xl:justify-center">
                                @if ($item->notes)
                                    <span class="tooltip tooltip-left" data-tip="{{ $item->notes }}" data-testid="row-note">
                                        <span class="flex h-8 w-8 items-center justify-center"><x-icon name="o-chat-bubble-left-ellipsis" class="h-5 w-5 text-neutral" /></span>
                                        <span class="sr-only">Nota del coordinador: {{ $item->notes }}</span>
                                    </span>
                                @endif
                            </div>

                            @if ($entry['anomalies'] !== [])
                                <ul class="col-span-3 flex flex-wrap gap-x-4 gap-y-1 text-sm @2xl:col-span-full @2xl:row-start-2" data-testid="row-anomalies">
                                    @foreach ($entry['anomalies'] as $anomaly)
                                        <li class="flex items-center gap-1.5"><x-icon name="o-exclamation-triangle" class="h-4 w-4 shrink-0 text-warning" /><span class="sr-only">Anomalía: </span>{{ $anomaly }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    @if ($items !== [])
        <div class="sticky bottom-0 z-10 flex items-baseline justify-between gap-4 rounded-b-box border-t border-base-300 bg-base-100 px-4 py-3 text-sm font-semibold sm:px-5" data-testid="inbox-detail-total">
            <span>Total</span>
            <span class="font-mono tabular-nums">{{ $selected['groups'] }} grupos · {{ $selected['expected'] }} esperados</span>
        </div>
    @endif
</div>
