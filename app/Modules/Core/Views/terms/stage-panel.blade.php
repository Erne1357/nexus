{{--
    El panel de una etapa del calendario (core::pages.term), dentro del x-drawer: «Etapa N de 8» con su estado, su
    nombre y el periodo; «General · todos los campus» con las fechas generales y «Quitar fechas»; y «Por campus ·
    ventana propia», un bloque por campus activo: con ventana propia muestra sus fechas y «Quitar ventana propia»;
    sin ella, «Agregar ventana propia» y las fechas generales que usa. Los campos se llaman como las claves de las
    Actions (general.starts_on, own.{campus}.ends_on), así sus errores caen en su campo. Nada se guarda hasta
    «Guardar». `$editing` trae la etapa, su número, su ventana general efectiva y `generalPreview`, las fechas generales que se editan
    en el panel (lo que usan los campus sin ventana propia); `close()` es del x-drawer.
--}}
@php($window = $editing['window'])

<div class="flex min-h-dvh flex-col" data-testid="stage-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2.5">
                <span class="font-mono text-sm font-semibold text-neutral">Etapa {{ $editing['number'] }} de {{ count(\App\Modules\Core\Enums\Stage::cases()) }}</span>
                <x-core::status-badge :tone="$window->status->tone()" :label="$window->status->label()" />
            </div>
            <h2 id="stage-drawer-title" class="mt-1.5 text-xl font-bold leading-tight">{{ $editing['stage']->label() }}</h2>
            <p class="mt-0.5 text-neutral">{{ $term->name }} · <span class="font-mono">{{ $term->code }}</span></p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-6 px-5 pb-8 pt-4">
        <section class="space-y-3" data-testid="general-section">
            <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">General · todos los campus</h3>
            <div class="grid grid-cols-2 gap-3">
                <x-input label="Desde" name="general.starts_on" type="date" wire:model="general.starts_on" data-testid="general-starts" />
                <x-input label="Hasta" name="general.ends_on" type="date" wire:model="general.ends_on" data-testid="general-ends" />
            </div>
            <x-button label="Quitar fechas" icon="o-trash" class="btn-ghost btn-sm text-error" wire:click="clearGeneral" spinner="clearGeneral" />
        </section>

        <section class="space-y-3" data-testid="campus-section">
            <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Por campus · ventana propia</h3>

            @foreach ($campuses as $campus)
                <div
                    wire:key="own-{{ $campus->id }}"
                    @class(['space-y-3 rounded-box border p-3', 'border-info/45 bg-info/5' => isset($own[$campus->id]), 'border-base-300' => ! isset($own[$campus->id])])
                    data-testid="campus-{{ $campus->code }}"
                >
                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-2">
                        <b class="text-[15px] font-bold">{{ $campus->name }}</b>
                        <span class="font-mono text-sm text-neutral">{{ $campus->code }}</span>

                        <span class="ms-auto">
                            @if (isset($own[$campus->id]))
                                <x-button label="Quitar ventana propia" icon="o-trash" class="btn-ghost btn-sm text-error" wire:click="removeCampusWindow('{{ $campus->id }}')" spinner="removeCampusWindow('{{ $campus->id }}')" data-testid="remove-{{ $campus->code }}" />
                            @else
                                <x-button label="Agregar ventana propia" icon="o-plus" class="btn-outline btn-sm" wire:click="addCampusWindow('{{ $campus->id }}')" spinner="addCampusWindow('{{ $campus->id }}')" data-testid="add-{{ $campus->code }}" />
                            @endif
                        </span>
                    </div>

                    @if (isset($own[$campus->id]))
                        <div class="grid grid-cols-2 gap-3">
                            <x-input label="Desde" name="own.{{ $campus->id }}.starts_on" type="date" wire:model="own.{{ $campus->id }}.starts_on" data-testid="own-{{ $campus->code }}-starts" />
                            <x-input label="Hasta" name="own.{{ $campus->id }}.ends_on" type="date" wire:model="own.{{ $campus->id }}.ends_on" data-testid="own-{{ $campus->code }}-ends" />
                        </div>
                    @else
                        <p class="flex flex-wrap items-center justify-between gap-x-3 text-neutral" data-testid="general-preview-{{ $campus->code }}">
                            {{ $editing['generalPreview'] === null ? 'Sin fechas generales.' : 'Usa las fechas generales' }}
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
