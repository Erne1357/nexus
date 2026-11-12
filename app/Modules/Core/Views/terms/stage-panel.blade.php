{{--
    El panel de una etapa del calendario (core::pages.term), dentro del x-drawer: «Etapa N de 8» con su estado, su
    nombre y el periodo; «General · todas las modalidades» con las fechas generales y «Quitar fechas»; y «Por
    modalidad · ventana propia» (M4), un bloque por modalidad activa: con ventana propia muestra sus fechas y «Quitar
    ventana propia»; sin ella, «Agregar ventana propia» y las fechas generales que usa. Los campos se llaman como las
    claves de las Actions (general.starts_on, own.{modalidad}.ends_on), así sus errores caen en su campo. Nada se guarda
    hasta «Guardar». `$editing` trae la etapa, su número, su ventana general efectiva y `generalPreview`, las fechas
    generales que se editan en el panel (lo que usan las modalidades sin ventana propia; null si no forman una ventana)
    y `generalEmpty`, si ambas están vacías; `close()` es del x-drawer.
--}}
@php($window = $editing['window'])

<div class="flex min-h-dvh flex-col" data-testid="stage-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2.5">
                <span class="font-mono text-sm font-semibold text-neutral">Etapa {{ $editing['number'] }} de {{ count(\App\Modules\Core\Enums\Stage::cases()) }}</span>
                <x-core::status-badge :tone="$window->status->tone()" :label="$window->status->label()" :icon="$window->status->icon()" />
            </div>
            <h2 id="stage-drawer-title" class="mt-1.5 text-xl font-bold leading-tight">{{ $editing['stage']->label() }}</h2>
            <p class="mt-0.5 text-neutral">{{ $term->name }} · <span class="font-mono">{{ $term->code }}</span></p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-6 px-5 pb-8 pt-4">
        <section class="space-y-3" data-testid="general-section">
            <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">General · todas las modalidades</h3>
            <div class="grid grid-cols-2 gap-3">
                <x-input label="Desde" name="general.starts_on" type="date" wire:model="general.starts_on" data-testid="general-starts" />
                <x-input label="Hasta" name="general.ends_on" type="date" wire:model="general.ends_on" data-testid="general-ends" />
            </div>
            <x-button label="Quitar fechas" icon="o-trash" class="btn-ghost btn-sm text-error" wire:click="clearGeneral" spinner="clearGeneral" />
        </section>

        <section class="space-y-3" data-testid="modality-section">
            <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Por modalidad · ventana propia</h3>

            @foreach ($modalities as $modality)
                <div
                    wire:key="own-{{ $modality->id }}"
                    @class(['space-y-3 rounded-box border p-3', 'border-info/45 bg-info/5' => isset($own[$modality->id]), 'border-base-300' => ! isset($own[$modality->id])])
                    data-testid="modality-{{ $modality->code }}"
                >
                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-2">
                        <b class="text-base font-bold">{{ $modality->name }}</b>
                        <span class="font-mono text-sm text-neutral">{{ $modality->code }}</span>

                        <span class="ms-auto">
                            @if (isset($own[$modality->id]))
                                <x-button label="Quitar ventana propia" icon="o-trash" class="btn-ghost btn-sm text-error" wire:click="removeModalityWindow('{{ $modality->id }}')" spinner="removeModalityWindow('{{ $modality->id }}')" data-testid="remove-{{ $modality->code }}" />
                            @else
                                <x-button label="Agregar ventana propia" icon="o-plus" class="btn-outline btn-sm" wire:click="addModalityWindow('{{ $modality->id }}')" spinner="addModalityWindow('{{ $modality->id }}')" data-testid="add-{{ $modality->code }}" />
                            @endif
                        </span>
                    </div>

                    @if (isset($own[$modality->id]))
                        <div class="grid grid-cols-2 gap-3">
                            <x-input label="Desde" name="own.{{ $modality->id }}.starts_on" type="date" wire:model="own.{{ $modality->id }}.starts_on" data-testid="own-{{ $modality->code }}-starts" />
                            <x-input label="Hasta" name="own.{{ $modality->id }}.ends_on" type="date" wire:model="own.{{ $modality->id }}.ends_on" data-testid="own-{{ $modality->code }}-ends" />
                        </div>
                    @else
                        <p class="flex flex-wrap items-center justify-between gap-x-3 text-neutral" data-testid="general-preview-{{ $modality->code }}">
                            {{ $editing['generalPreview'] !== null ? 'Usa las fechas generales' : ($editing['generalEmpty'] ? 'Sin fechas generales.' : 'Fechas generales incompletas.') }}
                            @if ($editing['generalPreview'] !== null)
                                <span class="font-mono text-base-content">{{ $editing['generalPreview'] }}</span>
                            @endif
                        </p>
                    @endif
                </div>
            @endforeach
        </section>
    </div>

    <footer class="sticky bottom-0 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-base-300 bg-base-100 py-3 ps-5 pe-4">
        <p class="text-sm text-neutral">Cada cambio queda en la bitácora.</p>
        <div class="flex gap-2 max-md:w-full">
            <x-button label="Cancelar" class="max-md:flex-1" @click="close()" />
            <x-button label="Guardar" icon="o-check" class="btn-primary max-md:flex-1" wire:click="saveStage" spinner="saveStage" data-testid="save-stage" />
        </div>
    </footer>
</div>
