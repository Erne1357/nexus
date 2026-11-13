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
    `$suggestedSize`.
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

                    @if ($editing)
                        <x-input label="Alumnos esperados" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="{{ strlen((string) $maxExpected) }}" oninput="this.value = this.value.replace(/\D/g, '')"
                            wire:model="form.{{ $item->id }}.expected_students" class="text-right font-mono" data-testid="field-expected" />
                        <div class="flex items-end gap-3">
                            <div class="min-w-0 flex-1">
                                <x-input label="Grupos solicitados" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="{{ strlen((string) $maxGroups) }}" oninput="this.value = this.value.replace(/\D/g, '')"
                                    wire:model="form.{{ $item->id }}.requested_groups" class="text-right font-mono" data-testid="field-groups" />
                            </div>
                            <x-button icon="o-sparkles" class="btn-sm mb-1" wire:click="useSuggested('{{ $item->id }}')" spinner="useSuggested('{{ $item->id }}')" data-testid="use-suggested"><span x-text="'Usar sugerido: ' + Math.ceil(Math.max(0, parseInt($wire.form[{{ \Illuminate\Support\Js::from($item->id) }}]?.expected_students) || 0) / {{ $suggestedSize }}) + ' ({{ $suggestedSize }} por grupo)'">Usar sugerido: {{ $suggested[$item->id] ?? 0 }} ({{ $suggestedSize }} por grupo)</span></x-button>
                        </div>
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
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="{{ $label }}">Nota</dt>
                                <dd class="min-w-0 text-right text-sm whitespace-pre-line" data-readonly-value="notes">{{ $item->notes ?: 'Sin nota' }}</dd>
                            </div>
                        </dl>
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
