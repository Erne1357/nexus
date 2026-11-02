{{--
    El panel de una materia de la propuesta (academic-load::pages.proposal), dentro del x-drawer: su clave, nombre,
    departamento y horas; los elegibles; los alumnos esperados, los grupos (con «Usar sugerido») y una nota. Si la
    fila está bloqueada o el usuario no puede editar, los valores van como texto en Mono (no campos deshabilitados)
    y se dice por qué.
    `$item` es la fila de ProposalOverview y `$lock`, su motivo de bloqueo o null; `close()` es del x-drawer.
    Si el departamento de la materia regresó su parte, repite su comentario; si la fila es rara (ProposalAnomalies),
    la advierte con icono y texto, sin impedir guardar ni enviar. «Usar sugerido» dice el número (`$suggested`; mientras se teclea lo recalcula Alpine con `$suggestedSize`).
--}}
@php
    $readonly = $lock !== null || ! $canEdit;
    $returned = $overview->reviewFor($item->subject->department_id);
    $returned = $returned?->status === \App\Modules\AcademicLoad\Enums\ProposalReviewStatus::Returned ? $returned : null;
    $anomalies = $overview->anomaliesFor($item);
@endphp
<div class="flex min-h-dvh flex-col" data-testid="item-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <span class="font-mono text-[15px] font-semibold">{{ $item->subject->code }}</span>
            <h2 id="proposal-drawer-title" class="mt-0.5 text-xl font-bold leading-tight">{{ $item->subject->name }}</h2>
            <p class="mt-1 text-neutral"><span class="font-mono">{{ $item->subject->department->code }}</span> · {{ $item->subject->department->name }} · Semestre {{ $item->semester }}@if ($item->subject->needs_lab) · Requiere laboratorio @endif</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-5 px-5 pt-4 pb-8">
        @if ($lock)
            <x-alert icon="o-lock-closed" class="alert-warning alert-soft text-sm" :title="$lock" />
        @endif

        @if ($returned)
            <x-alert icon="o-arrow-uturn-left" class="alert-error alert-soft items-start text-sm" data-testid="panel-returned">
                <span><span class="font-semibold">{{ $item->subject->department->name }} regresó su parte</span>@if ($returned->comment): “{{ $returned->comment }}”@endif</span>
            </x-alert>
        @endif

        @if ($anomalies !== [])
            <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft items-start text-sm" data-testid="panel-anomalies">
                <span><span class="font-semibold">Revisa esta materia:</span> {{ implode('; ', $anomalies) }}. Se puede enviar así.</span>
            </x-alert>
        @endif

        <div class="flex items-baseline justify-between rounded-box border border-base-300 px-4 py-3">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Elegibles</span>
            <span class="font-mono text-2xl font-semibold tabular-nums">{{ $item->eligible_students }}</span>
        </div>

        @if ($readonly)
            <dl class="space-y-4" data-testid="panel-readonly">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Alumnos esperados</dt>
                    <dd class="font-mono text-lg font-semibold tabular-nums" data-readonly-value="expected_students">{{ $item->expected_students }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Grupos solicitados</dt>
                    <dd class="font-mono text-lg font-semibold tabular-nums" data-readonly-value="requested_groups">{{ $item->requested_groups }}</dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Nota</dt>
                    <dd class="font-mono text-sm whitespace-pre-line" data-readonly-value="notes">{{ $item->notes ?: 'Sin nota' }}</dd>
                </div>
            </dl>
            <div class="flex justify-end">
                <x-button label="Cerrar" class="btn-ghost" @click="close()" />
            </div>
        @else
        <x-form wire:submit="save" class="gap-3!">
            <x-input label="Alumnos esperados" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="{{ strlen((string) $maxExpected) }}" oninput="this.value = this.value.replace(/\D/g, '')" wire:model="expected_students" class="font-mono" data-testid="field-expected" />
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <x-input label="Grupos solicitados" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="{{ strlen((string) $maxGroups) }}" oninput="this.value = this.value.replace(/\D/g, '')" wire:model="requested_groups" class="font-mono" data-testid="field-groups" />
                </div>
                <x-button icon="o-sparkles" class="btn-sm mb-1" wire:click="useSuggested" spinner="useSuggested" data-testid="use-suggested"><span x-text="'Usar sugerido: ' + Math.ceil(Math.max(0, parseInt($wire.expected_students) || 0) / {{ $suggestedSize }}) + ' ({{ $suggestedSize }} por grupo)'">Usar sugerido: {{ $suggested }} ({{ $suggestedSize }} por grupo)</span></x-button>
            </div>
            <x-textarea label="Nota" wire:model="notes" rows="3" maxlength="{{ \App\Modules\AcademicLoad\Rules\ProposalItemRules::MAX_NOTES_LENGTH }}" data-testid="field-notes" />

            <div class="flex justify-end gap-2">
                <x-button label="Cancelar" class="btn-ghost" @click="close()" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" data-testid="save-item" />
            </div>
        </x-form>
        @endif
    </div>
</div>
