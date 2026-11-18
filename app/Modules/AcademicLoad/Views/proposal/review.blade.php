{{--
    La pestaña «Revisar proyección» de la propuesta (tarea 5.6, opción C «Revisión de la proyección», elegida por el
    usuario el 17 de noviembre de 2026; maqueta `.impeccable/mocks/decision/demanda-2026-11/propuesta-c.html`,
    `#vista=revisar`). Arriba, el filtro por motivo («Todas · La proyección cambió · Se aparta del sugerido · Especialidad
    · Sin tomas recientes · Sin historia», con sus cifras de lo pendiente; un motivo sin filas no se muestra) y que nada de esto impide enviar;
    si el usuario corrige unas columnas y no otras (M7), una línea por cada otra columna con lo que tiene por revisar y
    quién lo revisa.

    Luego una tarjeta por fila pendiente (materia × campus), 20 a la vez con «Mostrar N más»: a la izquierda los motivos
    como insignias (la diferencia con su porcentaje), la etiqueta del campus, la clave, el nombre y el departamento; al
    centro qué se capturó y qué sugiere Nexus, y la evidencia (academic-load::proposal.forecast-evidence); a la derecha
    las acciones según el estado de la fila: «Usar N · M» y «Mantener» si la proyección cambió, «Usar sugerido» (con paquetes,
    los de paquetes más los sueltos sugeridos) y «Dejar» si se aparta del sugerido, «Está bien» si es de especialidad, sin
    tomas recientes o sin historia sin tocar, y siempre «Abrir en el panel»; el error de una acción queda en su tarjeta. Una fila que el usuario no corrige (otro campus, M7, o una propuesta enviada) va sin botones; una cuyo
    departamento aceptó su parte dice por qué. Desde 56 rem de caja la tarjeta va en tres columnas (17 rem, el resto y 13 rem); más angosta se
    apila (como `shots/propuesta-c-revisar-390.png`). Al final, plegadas, «Revisadas (N)» con lo que quedó.
--}}
@php
    $filterOptions = ['' => 'Todas', ...collect($reasons)->mapWithKeys(fn ($option) => [$option->filter() => $option->label()])->all()];
    $filterOptions = array_filter($filterOptions, fn ($optionLabel, $filter) => $filter === '' || $filter === $reason || $reviewCounts[$filter] > 0, ARRAY_FILTER_USE_BOTH);
    $currentReason = \App\Modules\AcademicLoad\Enums\ForecastReason::fromFilter($reason);
    $threshold = $projection->settings->highlightThreshold;
@endphp
<div class="space-y-3" data-testid="forecast-review">
    @if ($generalLock)
        <x-alert icon="o-lock-closed" class="alert-warning alert-soft text-sm" :title="$generalLock->message('').' Lo que sugiere Nexus queda a la vista para comparar.'" data-testid="review-lock" />
    @endif

    <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
        <div role="group" aria-label="Motivo" data-testid="reason-filter"
            class="max-desktop:grid max-desktop:w-full max-desktop:grid-cols-3 max-desktop:gap-1 desktop:join">
            @foreach ($filterOptions as $filter => $filterLabel)
                @php
    $testKey = $filter === '' ? 'todas' : $filter;
@endphp
                <button type="button" wire:click="filterReason('{{ $filter }}')" aria-pressed="{{ $reason === $filter ? 'true' : 'false' }}" data-testid="reason-{{ $testKey }}"
                    @class(['btn btn-sm desktop:join-item max-desktop:h-auto max-desktop:min-h-11 max-desktop:min-w-0 max-desktop:flex-col max-desktop:gap-0 max-desktop:py-1 max-desktop:whitespace-normal', 'btn-primary' => $reason === $filter])>
                    <span>{{ $filterLabel }}</span>
                    <span class="font-mono text-[12.5px] opacity-85" data-testid="reason-count-{{ $testKey }}">{{ $reviewCounts[$filter] }}</span>
                </button>
            @endforeach
        </div>
        <p class="text-[12.5px] text-neutral">Se resalta lo que se aparta {{ $threshold }} % o más de lo sugerido; nada de esto impide enviar.</p>
    </div>

    @foreach ($reviewOthers as $other)
        <p class="flex items-center gap-1.5 text-[12.5px] text-neutral" data-testid="review-other">
            <x-icon name="o-lock-closed" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $other }}
        </p>
    @endforeach

    <div class="@container rounded-box border border-base-300 bg-base-100" data-testid="review-list">
        @forelse ($reviewCards as $card)
            @php
                $cardItem = $card->item;
                $cardKey = \App\Modules\AcademicLoad\Queries\ProposalOverview::columnKey($card->campus);
                $cardSubject = $card->subject->subject;
                $state = $card->suggestion->state;
                $canAct = ($columns[$cardKey]['editable'] ?? false) && $overview->isEditable($cardItem);
                $ownLock = $locks[$cardItem->id] ?? null;
                $difference = $card->difference();
                $specialtyId = $overview->specialtyFor($card->subject);
                $suggestedGroups = $card->effectiveGroups();
            @endphp
            <article id="review-{{ $cardItem->id }}" data-item="review-{{ $cardItem->id }}" tabindex="-1" wire:key="review-{{ $cardItem->id }}" data-testid="review-card-{{ $cardItem->id }}"
                class="grid gap-x-6 gap-y-3 border-base-300 px-4 py-4 outline-none not-first:border-t data-[found]:ring-2 data-[found]:ring-inset data-[found]:ring-primary @min-[56rem]:grid-cols-[17rem_minmax(0,1fr)_13rem] @min-[56rem]:px-5">
                <div class="min-w-0 space-y-1">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($card->reasons as $cardReason)
                            <x-core::status-badge :tone="$cardReason->tone()" :icon="$cardReason->icon()" class="h-auto! min-h-6.5 py-0.5 text-left whitespace-normal!"
                                :label="$cardReason === \App\Modules\AcademicLoad\Enums\ForecastReason::LargeDifference && $difference ? $cardReason->label().' · '.str_replace(' ', mb_chr(0xA0), ($difference['field'] === 'groups' ? 'grupos' : 'esperados').' '.$difference['percent']) : $cardReason->label()" />
                        @endforeach
                    </div>
                    <p class="flex items-center gap-1.5 pt-1">
                        <x-academic-load::campus-tag :campus="$card->campus" />
                        <span class="font-mono text-[15px] font-semibold">{{ $cardSubject->code }}</span>
                    </p>
                    <h3 class="font-semibold leading-snug">{{ $cardSubject->name }}</h3>
                    <p class="text-[12.5px] text-neutral"><span class="font-mono" title="{{ $cardSubject->department->name }}">{{ $cardSubject->department->code }}</span> · Semestre {{ $card->subject->semester }}@if ($cardSubject->needs_lab) · laboratorio @endif</p>
                </div>

                <div class="min-w-0 space-y-2.5">
                    <p class="max-w-[70ch] text-sm" data-testid="review-what">
                        @if ($state === \App\Modules\AcademicLoad\Enums\SuggestionState::Changed)
                            Ahora sugiere <span class="font-mono font-semibold">{{ $card->suggestion->newExpected }}</span> esperados y <span class="font-mono font-semibold">{{ $card->suggestion->newGroups }}</span> grupos; cuando corregiste sugería <span class="font-mono">{{ $cardItem->suggested_expected_students }}</span> y <span class="font-mono">{{ $cardItem->suggested_groups }}</span>. Capturaste <span class="font-mono font-semibold">{{ $cardItem->expected_students }}</span> y <span class="font-mono font-semibold">{{ $cardItem->requested_groups }}</span>.
                        @elseif ($state === \App\Modules\AcademicLoad\Enums\SuggestionState::Captured)
                            Capturaste <span class="font-mono font-semibold">{{ $cardItem->expected_students }}</span> esperados y <span class="font-mono font-semibold">{{ $cardItem->requested_groups }}</span> grupos; Nexus sugiere <span class="font-mono font-semibold">{{ $card->suggestedExpected() }}</span> y @include('academic-load::proposal.suggested-groups', ['forecastRow' => $card]).
                        @elseif ($card->has(\App\Modules\AcademicLoad\Enums\ForecastReason::NoUptake))
                            Tiene elegibles, pero nadie la ha tomado en los últimos {{ $projection->settings->historyTerms }} {{ $projection->termType() }}. Nexus sugiere <span class="font-mono font-semibold">{{ $card->suggestedExpected() }}</span> esperados y @include('academic-load::proposal.suggested-groups', ['forecastRow' => $card, 'noun' => true]).
                        @elseif ($specialtyId !== null && $card->has(\App\Modules\AcademicLoad\Enums\ForecastReason::Specialty))
                            {{ $specialtyNames[$specialtyId] ?? 'Especialidad' }}: los elegibles son quienes la eligieron. Nexus sugiere <span class="font-mono font-semibold">{{ $card->suggestedExpected() }}</span> esperados y @include('academic-load::proposal.suggested-groups', ['forecastRow' => $card, 'noun' => true]).
                        @else
                            Nexus sugiere <span class="font-mono font-semibold">{{ $card->suggestedExpected() }}</span> esperados y @include('academic-load::proposal.suggested-groups', ['forecastRow' => $card, 'noun' => true]) sin historia propia de la materia.
                        @endif
                    </p>
                    @include('academic-load::proposal.forecast-evidence', ['forecastRow' => $card, 'history' => $forecastHistory[$cardItem->id] ?? null])
                </div>

                <div class="flex flex-col gap-1.5 @max-[56rem]:flex-row @max-[56rem]:flex-wrap">
                    @if ($canAct)
                        <div class="contents" data-testid="review-actions-{{ $cardItem->id }}">
                            @if ($state === \App\Modules\AcademicLoad\Enums\SuggestionState::Changed)
                                <x-button :label="'Usar '.$card->suggestion->newExpected.' · '.$card->suggestion->newGroups" class="btn-sm btn-outline @max-[56rem]:flex-auto whitespace-nowrap max-md:min-h-11"
                                    wire:click="resolveChange('{{ $cardItem->id }}', 'use')" spinner="resolveChange('{{ $cardItem->id }}', 'use')" data-testid="review-use-{{ $cardItem->id }}" />
                                <x-button :label="'Mantener '.$cardItem->expected_students.' · '.$cardItem->requested_groups" class="btn-sm @max-[56rem]:flex-auto whitespace-nowrap max-md:min-h-11"
                                    wire:click="resolveChange('{{ $cardItem->id }}', 'keep')" spinner="resolveChange('{{ $cardItem->id }}', 'keep')" data-testid="review-keep-{{ $cardItem->id }}" />
                            @elseif ($state === \App\Modules\AcademicLoad\Enums\SuggestionState::Captured)
                                <x-button :label="'Usar sugerido '.$card->suggestedExpected().' · '.$suggestedGroups" class="btn-sm btn-outline @max-[56rem]:flex-auto whitespace-nowrap max-md:min-h-11"
                                    wire:click="applySuggestion('{{ $cardItem->id }}')" spinner="applySuggestion('{{ $cardItem->id }}')" data-testid="review-suggested-{{ $cardItem->id }}" />
                                <x-button :label="'Dejar '.$cardItem->expected_students.' · '.$cardItem->requested_groups" class="btn-sm @max-[56rem]:flex-auto whitespace-nowrap max-md:min-h-11"
                                    wire:click="markReviewed('{{ $cardItem->id }}')" spinner="markReviewed('{{ $cardItem->id }}')" data-testid="review-leave-{{ $cardItem->id }}" />
                            @else
                                <x-button label="Está bien" class="btn-sm btn-outline @max-[56rem]:flex-auto whitespace-nowrap max-md:min-h-11"
                                    wire:click="markReviewed('{{ $cardItem->id }}')" spinner="markReviewed('{{ $cardItem->id }}')" data-testid="review-ok-{{ $cardItem->id }}" />
                            @endif
                            <x-button label="Abrir en el panel" icon="o-arrow-top-right-on-square" class="btn-sm btn-ghost @max-[56rem]:flex-auto whitespace-nowrap max-md:min-h-11"
                                wire:click="edit('{{ $cardItem->subject_id }}')" data-opener data-testid="review-open-{{ $cardItem->id }}" />
                        </div>
                    @else
                        @if ($ownLock !== null && ! $ownLock->isGeneral())
                            <p class="flex items-center gap-1.5 text-[12.5px] text-neutral"><x-icon name="o-lock-closed" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $ownLock->short($cardSubject->department->code) }}</p>
                        @endif
                        <x-button label="Ver en el panel" icon="o-eye" class="btn-sm btn-ghost max-md:min-h-11"
                            wire:click="edit('{{ $cardItem->subject_id }}')" data-opener data-testid="review-view-{{ $cardItem->id }}" />
                    @endif
                    @if (isset($reviewErrors[$cardItem->id]))
                        <p class="flex items-start gap-1.5 text-[12.5px] text-error" role="alert" data-testid="review-error-{{ $cardItem->id }}">
                            <x-icon name="o-x-circle" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span>{{ $reviewErrors[$cardItem->id] }}</span>
                        </p>
                    @endif
                </div>
            </article>
        @empty
            <div class="flex items-start gap-3 px-5 py-5" data-testid="review-empty">
                <x-icon name="o-check-circle" class="h-6 w-6 shrink-0 text-success" aria-hidden="true" />
                <p class="text-sm"><span class="font-semibold">Nada por revisar{{ $currentReason ? ' con este motivo' : '' }}.</span> <span class="text-neutral">Lo que corriges aparece aquí si se aparta {{ $threshold }} % o más de lo sugerido o si la proyección cambia.</span></p>
            </div>
        @endforelse
    </div>

    @if ($reviewMore > 0)
        <x-button :label="'Mostrar '.$reviewMore.' más'" class="btn-sm btn-ghost max-md:w-full max-md:min-h-11" wire:click="showMoreReviews" spinner="showMoreReviews" data-testid="review-more" />
    @endif

    @if ($reviewedRows !== [])
        <details class="group rounded-box border border-base-300 bg-base-100" data-testid="review-done">
            <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-5 py-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral [&::-webkit-details-marker]:hidden">
                <x-icon name="o-chevron-right" class="h-4 w-4 transition-transform group-open:rotate-90 motion-reduce:transition-none" aria-hidden="true" />
                Revisadas ({{ count($reviewedRows) }})
            </summary>
            @foreach ($reviewedRows as $done)
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 border-t border-base-300 px-5 py-2 text-sm" wire:key="reviewed-{{ $done->item->id }}" data-testid="reviewed-{{ $done->item->id }}">
                    <x-icon name="o-check-circle" class="h-4 w-4 shrink-0 text-success" aria-hidden="true" />
                    <x-academic-load::campus-tag :campus="$done->campus" />
                    <span class="font-mono font-semibold">{{ $done->subject->subject->code }}</span>
                    <span class="min-w-0">{{ $done->subject->subject->name }}</span>
                    <span class="text-neutral">· Quedó en <span class="font-mono">{{ $done->item->expected_students }} · {{ $done->item->requested_groups }}</span></span>
                </div>
            @endforeach
        </details>
    @endif
</div>
