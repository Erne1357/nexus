{{--
    El panel de una materia de la propuesta (academic-load::pages.proposal), dentro del x-drawer (maqueta U1, opción A):
    su clave, nombre, departamento, semestre y si lleva laboratorio; con varias columnas, lo de los campus juntos
    (posibles, esperados y grupos); y una sección por campus con sus alumnos posibles y, si el usuario corrige esa fila
    (su campus, M7, y su estado), sus alumnos esperados, sus grupos (con «Usar sugerido») y su nota, que guarda «Guardar»
    para todas a la vez. La sección de otro campus va de solo lectura, con fondo tenue, «Solo lectura» y quién la captura;
    una fila bloqueada por su estado muestra sus valores como texto en Mono (no campos deshabilitados).
    `$row` es la ProposalSubject; `close()` es del x-drawer. Si el departamento de la materia aceptó su parte o la
    regresó, lo dice arriba con su comentario; si alguna fila es rara (ProposalAnomalies), la advierte con icono y texto,
    sin impedir guardar ni enviar. «Usar sugerido» dice el número, que mientras se teclea recalcula Alpine con
    `$suggestedSize` (con paquetes, los de paquetes más los sueltos sugeridos, como SuggestedRowGroups).

    Con paquetes de primer semestre (tarea 5.6b), bajo los grupos de un campus que abre grupos por paquetes: «5 de paquetes + 3
    sueltos» (los grupos son el total de la fila; los sueltos, para rezagados y repetidores).

    Con la proyección (tarea 5.6, opción C), cada campus lleva al final «Proyección de Nexus» con el estado de su fila
    (Sugerido, Corregido o La proyección cambió), lo que sugiere y, si se aparta, cuánto; si cambió y el usuario corrige
    esa fila, «Usar N · M» y «Mantener» (ResolveForecastChange); y la misma evidencia de la tarjeta de «Revisar proyección»
    (academic-load::proposal.forecast-evidence).
--}}
@php
    $review = $overview->reviewFor($row->subject->department_id);
    $returned = $review?->status === \App\Modules\AcademicLoad\Enums\ProposalReviewStatus::Returned ? $review : null;
    $anomalies = $overview->subjectAnomalies($row);
    $firstLock = collect($row->items())->map(fn ($item) => $locks[$item->id] ?? null)->filter()->first();
    $multi = count($columns) > 1;
    $label = 'text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral';
@endphp
<div class="flex min-h-dvh flex-col" data-testid="item-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <span class="font-mono text-[15px] font-semibold">{{ $row->subject->code }}</span>
            <h2 id="proposal-drawer-title" class="mt-0.5 text-xl font-bold leading-tight">{{ $row->subject->name }}</h2>
            <p class="mt-1 text-neutral"><span class="font-mono">{{ $row->subject->department->code }}</span> · {{ $row->subject->department->name }} · Semestre {{ $row->semester }}@if ($row->subject->needs_lab) · Requiere laboratorio @endif</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-4 px-5 pt-4 pb-8">
        @if ($firstLock)
            <x-alert icon="o-lock-closed" class="alert-warning alert-soft text-sm" :title="$firstLock->message($row->subject->department->code)" data-testid="panel-lock" />
        @endif

        @if ($returned)
            <x-alert icon="o-arrow-uturn-left" class="alert-error alert-soft items-start text-sm" data-testid="panel-returned">
                <span><span class="font-semibold">{{ $row->subject->department->name }} regresó su parte</span>@if ($returned->comment): “{{ $returned->comment }}”@endif</span>
            </x-alert>
        @endif

        @if ($anomalies !== [])
            <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft items-start text-sm" data-testid="panel-anomalies">
                <span><span class="font-semibold">Revisa esta materia:</span> {{ implode('; ', $anomalies) }}. Se puede enviar así.</span>
            </x-alert>
        @endif

        @if ($multi)
            <div class="rounded-box border border-base-300 px-4 py-2.5" data-testid="panel-sum">
                <p class="{{ $label }}">Entre los {{ count($columns) === 2 ? 'dos' : count($columns) }} campus</p>
                <p class="mt-0.5 text-sm"><span class="font-mono font-semibold">{{ $row->eligible() }}</span> posibles · <span class="font-mono font-semibold">{{ $row->expected() }}</span> esperados · <span class="font-mono font-semibold">{{ $row->groups() }}</span> {{ $row->groups() === 1 ? 'grupo' : 'grupos' }}</p>
            </div>
        @endif

        <x-form wire:submit="save" class="gap-4!" no-separator>
            @foreach ($columns as $key => $column)
                @php
                    $item = $row->cells[$key] ?? null;
                    $editing = $item !== null && array_key_exists($item->id, $form);
                    $readonlyCampus = ! $column['editable'];
                @endphp
                @continue($item === null)
                <section @class(['space-y-3 rounded-box border border-base-300 px-4 py-3', 'bg-secondary/40' => $readonlyCampus])
                    aria-labelledby="panel-campus-{{ $item->id }}" data-testid="panel-campus-{{ $column['campus']?->code ?? 'unica' }}" @if ($readonlyCampus) data-readonly-section @endif>
                    <h3 id="panel-campus-{{ $item->id }}" class="flex flex-wrap items-center gap-2 font-semibold">
                        <x-academic-load::campus-tag :campus="$column['campus']" size="lg" />
                        <span>{{ $column['campus']?->name ?? 'Alumnos de la modalidad' }}</span>
                        @if ($readonlyCampus)
                            <span class="inline-flex items-center gap-0.5 text-xs font-normal text-neutral"><x-icon name="o-lock-closed" class="h-3.5 w-3.5" aria-hidden="true" />Solo lectura</span>
                        @endif
                    </h3>
                    @if ($readonlyCampus)
                        <p class="text-sm text-neutral" data-testid="panel-readonly-reason">{{ $column['readonly'] }}</p>
                    @endif

                    <div class="flex items-baseline justify-between gap-3">
                        <span class="{{ $label }}">Alumnos posibles</span>
                        <span class="font-mono text-2xl font-semibold tabular-nums">{{ $item->eligible_students }}</span>
                    </div>

                    @php
                        $looseGroups = max(0, $item->requested_groups - $item->package_groups);
                        $looseLabel = $looseGroups === 1 ? 'suelto' : 'sueltos';
                    @endphp
                    @if ($editing)
                        <x-input label="Alumnos esperados" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="{{ strlen((string) $maxExpected) }}" oninput="this.value = this.value.replace(/\D/g, '')"
                            wire:model="form.{{ $item->id }}.expected_students" class="text-right font-mono" data-testid="field-expected" />
                        <div class="flex items-end gap-3">
                            <div class="min-w-0 flex-1">
                                <x-input label="Grupos solicitados" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="{{ strlen((string) $maxGroups) }}" oninput="this.value = this.value.replace(/\D/g, '')"
                                    wire:model="form.{{ $item->id }}.requested_groups" class="text-right font-mono" data-testid="field-groups" />
                            </div>
                            <x-button icon="o-sparkles" class="btn-sm mb-1" wire:click="useSuggested('{{ $item->id }}')" spinner="useSuggested('{{ $item->id }}')" data-testid="use-suggested">@php
                                $rowNewStudents = ($panelMethods[$item->id] ?? $item->forecast_method) === \App\Modules\Demand\Enums\ForecastMethod::FirstSemester ? ($panelNewStudents[$item->campus_id ?? ''] ?? 0) : 0;
                            @endphp<span x-text="'Usar sugerido: ' + ((expected) => {{ $item->package_groups }} > 0 ? {{ $item->package_groups }} + Math.ceil(Math.max(0, expected - {{ $rowNewStudents }}) / {{ $suggestedSize }}) : Math.ceil(expected / {{ $suggestedSize }}))(Math.max(0, parseInt($wire.form[{{ \Illuminate\Support\Js::from($item->id) }}]?.expected_students) || 0)) + ' ({{ $suggestedSize }} por grupo)'">Usar sugerido: {{ $suggested[$item->id] ?? 0 }} ({{ $suggestedSize }} por grupo)</span></x-button>
                        </div>
                        @if ($item->package_groups > 0)
                            <p class="-mt-1 flex items-start gap-1.5 text-sm" data-testid="panel-packages-{{ $column['campus']?->code ?? 'unica' }}"><x-icon name="o-rectangle-group" class="mt-0.5 h-4 w-4 shrink-0 text-neutral" aria-hidden="true" /><span><span class="font-mono font-semibold">{{ $item->package_groups }}</span> de paquetes + <span class="font-mono font-semibold">{{ $looseGroups }}</span> {{ $looseLabel }}</span></p>
                        @endif
                        <x-textarea label="Nota" wire:model="form.{{ $item->id }}.notes" rows="2" maxlength="{{ \App\Modules\AcademicLoad\Rules\ProposalItemRules::MAX_NOTES_LENGTH }}" data-testid="field-notes" />
                    @else
                        <dl class="space-y-2" data-testid="panel-readonly">
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="{{ $label }}">Alumnos esperados</dt>
                                <dd class="font-mono text-lg font-semibold tabular-nums" data-readonly-value="expected_students">{{ $item->expected_students }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="{{ $label }}">Grupos solicitados</dt>
                                <dd class="font-mono text-lg font-semibold tabular-nums" data-readonly-value="requested_groups">{{ $item->requested_groups }}</dd>
                            </div>
                            @if ($item->package_groups > 0)
                                <div class="flex items-baseline justify-between gap-3">
                                    <dt class="{{ $label }}">Grupos de paquetes</dt>
                                    <dd class="text-right text-sm" data-testid="panel-packages-{{ $column['campus']?->code ?? 'unica' }}"><span class="font-mono font-semibold">{{ $item->package_groups }}</span> de paquetes + <span class="font-mono font-semibold">{{ $looseGroups }}</span> {{ $looseLabel }}</dd>
                                </div>
                            @endif
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="{{ $label }}">Nota</dt>
                                <dd class="min-w-0 text-right text-sm whitespace-pre-line" data-readonly-value="notes">{{ $item->notes ?: 'Sin nota' }}</dd>
                            </div>
                        </dl>
                    @endif

                    @php
    $forecastRow = $projection->row($item);
@endphp
                    @if ($forecastRow !== null)
                        @php
                            $forecastState = $forecastRow->suggestion->state;
                            $forecastStateLabel = match ($forecastState) {
                                \App\Modules\AcademicLoad\Enums\SuggestionState::Suggested => ['o-sparkles', 'Sugerido', 'text-neutral'],
                                \App\Modules\AcademicLoad\Enums\SuggestionState::Changed => ['o-arrow-path', 'La proyección cambió', 'text-info'],
                                default => ['o-pencil', 'Corregido', 'text-neutral'],
                            };
                            $forecastDifference = $forecastRow->difference();
                        @endphp
                        <div class="space-y-2.5 border-t border-base-300 pt-3" data-testid="panel-forecast-{{ $column['campus']?->code ?? 'unica' }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="{{ $label }}">Proyección de Nexus</span>
                                <span class="inline-flex items-center gap-1 text-[12.5px] font-medium {{ $forecastStateLabel[2] }}"><x-icon :name="$forecastStateLabel[0]" class="h-3.5 w-3.5" aria-hidden="true" />{{ $forecastStateLabel[1] }}</span>
                            </div>
                            @if ($forecastState === \App\Modules\AcademicLoad\Enums\SuggestionState::Changed)
                                <x-alert icon="o-arrow-path" class="alert-info alert-soft items-start text-sm" data-testid="panel-forecast-change">
                                    <div class="space-y-2">
                                        <p>Ahora sugiere <span class="font-mono font-semibold">{{ $forecastRow->suggestion->newExpected }}</span> esperados y <span class="font-mono font-semibold">{{ $forecastRow->suggestion->newGroups }}</span> grupos; cuando corregiste sugería <span class="font-mono">{{ $item->suggested_expected_students }}</span> y <span class="font-mono">{{ $item->suggested_groups }}</span>.</p>
                                        @if ($editing)
                                            <div class="flex flex-wrap gap-2">
                                                <x-button :label="'Usar '.$forecastRow->suggestion->newExpected.' · '.$forecastRow->suggestion->newGroups" class="btn-sm btn-outline max-md:min-h-11" wire:click="resolveChange('{{ $item->id }}', 'use')" spinner="resolveChange('{{ $item->id }}', 'use')" data-testid="panel-use-{{ $item->id }}" />
                                                <x-button :label="'Mantener '.$item->expected_students.' · '.$item->requested_groups" class="btn-sm btn-ghost max-md:min-h-11" wire:click="resolveChange('{{ $item->id }}', 'keep')" spinner="resolveChange('{{ $item->id }}', 'keep')" data-testid="panel-keep-{{ $item->id }}" />
                                            </div>
                                        @endif
                                    </div>
                                </x-alert>
                            @else
                                <p class="text-sm">Nexus sugiere <span class="font-mono font-semibold">{{ $forecastRow->suggestedExpected() }}</span> esperados y @include('academic-load::proposal.suggested-groups', ['forecastRow' => $forecastRow, 'noun' => true]).@if ($forecastDifference) <span class="inline-flex items-center gap-1 font-medium text-warning"><x-icon name="o-arrows-up-down" class="h-3.5 w-3.5" aria-hidden="true" />Lo capturado se aparta: {{ $forecastDifference['field'] === 'groups' ? 'grupos' : 'esperados' }} {{ $forecastDifference['percent'] }}.</span>@endif</p>
                            @endif
                            @include('academic-load::proposal.forecast-evidence', ['forecastRow' => $forecastRow, 'history' => $forecastHistory[$item->id] ?? null])
                        </div>
                    @endif
                </section>
            @endforeach

            <div class="sticky bottom-0 -mx-5 flex justify-end gap-2 border-t border-base-300 bg-base-100 px-5 py-3">
                @if ($form !== [])
                    <x-button label="Cancelar" class="btn-ghost" @click="close()" />
                    <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" data-testid="save-item" />
                @else
                    <x-button label="Cerrar" class="btn-ghost" @click="close()" />
                @endif
            </div>
        </x-form>
    </div>
</div>
