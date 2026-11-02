{{--
    El panel de una materia de la propuesta (academic-load::pages.proposal), dentro del x-drawer: su clave, nombre,
    departamento y horas; los elegibles; los alumnos esperados, los grupos (con «Usar sugerido») y una nota. Si la
    fila está bloqueada o el usuario no puede editar, los campos van deshabilitados y se dice por qué.
    `$item` es la fila de ProposalOverview y `$lock`, su motivo de bloqueo o null; `close()` es del x-drawer.
--}}
@php($readonly = $lock !== null || ! $canEdit)
<div class="flex min-h-dvh flex-col" data-testid="item-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <span class="font-mono text-[15px] font-semibold">{{ $item->subject->code }}</span>
            <h2 id="proposal-drawer-title" class="mt-0.5 text-xl font-bold leading-tight">{{ $item->subject->name }}</h2>
            <p class="mt-1 text-neutral"><span class="font-mono">{{ $item->subject->department->code }}</span> · {{ $item->subject->department->name }}@if ($item->subject->needs_lab) · Requiere laboratorio @endif</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-5 px-5 pt-4 pb-8">
        @if ($lock)
            <x-alert icon="o-lock-closed" class="alert-warning alert-soft text-sm" :title="$lock" />
        @endif

        <div class="flex items-baseline justify-between rounded-box border border-base-300 px-4 py-3">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Elegibles</span>
            <span class="font-mono text-2xl font-semibold tabular-nums">{{ $item->eligible_students }}</span>
        </div>

        <x-form wire:submit="save" class="gap-3!">
            <x-input label="Alumnos esperados" type="number" wire:model="expected_students" min="0" max="{{ $maxExpected }}" class="font-mono" :disabled="$readonly" data-testid="field-expected" />
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <x-input label="Grupos solicitados" type="number" wire:model="requested_groups" min="0" max="{{ $maxGroups }}" class="font-mono" :disabled="$readonly" data-testid="field-groups" />
                </div>
                @unless ($readonly)
                    <x-button label="Usar sugerido" icon="o-sparkles" class="btn-sm mb-1" wire:click="useSuggested" spinner="useSuggested" data-testid="use-suggested" />
                @endunless
            </div>
            <x-textarea label="Nota" wire:model="notes" rows="3" maxlength="{{ \App\Modules\AcademicLoad\Rules\ProposalItemRules::MAX_NOTES_LENGTH }}" :disabled="$readonly" data-testid="field-notes" />

            @unless ($readonly)
                <div class="flex justify-end gap-2">
                    <x-button label="Cancelar" class="btn-ghost" @click="close()" />
                    <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" data-testid="save-item" />
                </div>
            @endunless
        </x-form>
    </div>
</div>
