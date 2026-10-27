@use('App\Modules\Imports\Enums\BatchStatus')

<div>
    <x-header title="Importaciones" subtitle="Carga un catálogo desde una exportación del SII: sube el archivo, revisa cada fila y aplica." separator progress-indicator />

    <x-steps wire:model="step" steps-color="step-primary" stepper-classes="w-full mb-6">
        <x-step step="1" text="Archivo">
            @if ($step === 1)
                <x-card shadow>
                    @if ($templates === [])
                        <x-alert
                            title="Todavía no hay plantillas de importación."
                            description="Llegan con cada catálogo: Students (tarea 1.4), Academic (1.9), Faculty (1.13) y Facilities (1.15)."
                            icon="o-information-circle"
                            class="alert-info"
                        />
                    @else
                        <x-form wire:submit="continue" no-separator>
                            <div class="grid gap-4 md:grid-cols-2">
                                <x-select label="Plantilla" name="template" wire:model.live="template" :options="$templates" placeholder="Elige qué catálogo importas" />

                                <x-file label="Archivo" name="file" wire:model="file" accept=".xlsx,.csv,.txt" hint="Exportación del SII en .xlsx, .csv o .txt, hasta 20 MB." />
                            </div>

                            @if ($definition?->requiresCampus())
                                <p class="text-sm" data-testid="import-campus">
                                    Campus:
                                    @if ($campus)
                                        <span class="font-medium">{{ $campus->code }} · {{ $campus->name }}</span>, el de la barra superior.
                                    @else
                                        elige en la barra superior el campus al que pertenece el archivo.
                                    @endif
                                </p>
                            @endif

                            <x-slot:actions>
                                <x-button label="Continuar" type="submit" icon-right="o-arrow-right" class="btn-primary" spinner="continue" />
                            </x-slot:actions>
                        </x-form>
                    @endif
                </x-card>

                <x-card title="Lotes recientes" subtitle="Retoma uno para ver su validación o aplicarlo." shadow class="mt-6" separator>
                    <x-table :headers="[
                        ['key' => 'created_at', 'label' => 'Subido'],
                        ['key' => 'original_name', 'label' => 'Archivo'],
                        ['key' => 'template', 'label' => 'Plantilla'],
                        ['key' => 'campus', 'label' => 'Campus'],
                        ['key' => 'status', 'label' => 'Estado'],
                    ]" :rows="$recent">
                        @scope('cell_created_at', $recentBatch)
                            {{ $recentBatch->created_at->format('d/m/Y H:i') }}
                        @endscope

                        @scope('cell_template', $recentBatch, $templates)
                            {{ collect($templates)->firstWhere('id', $recentBatch->template)['name'] ?? $recentBatch->template }}
                        @endscope

                        @scope('cell_campus', $recentBatch)
                            {{ $recentBatch->campus?->code ?? '—' }}
                        @endscope

                        @scope('cell_status', $recentBatch)
                            <x-badge :value="$recentBatch->status->label()" :class="$recentBatch->status->badgeClass()" />
                        @endscope

                        @scope('actions', $recentBatch)
                            <x-button
                                label="Ver"
                                wire:click="open('{{ $recentBatch->id }}')"
                                spinner="open('{{ $recentBatch->id }}')"
                                class="btn-ghost btn-sm"
                                data-testid="open-batch-{{ $recentBatch->id }}"
                            />
                        @endscope

                        <x-slot:empty>
                            <x-icon name="o-arrow-up-tray" label="Todavía no se ha importado ningún archivo." />
                        </x-slot:empty>
                    </x-table>
                </x-card>
            @endif
        </x-step>

        <x-step step="2" text="Columnas">
            @if ($step === 2 && $definition)
                <x-card shadow>
                    <x-form wire:submit="upload" no-separator>
                        <p class="text-sm">
                            <span class="font-medium">{{ $definition->label() }}</span>
                            desde <span class="font-medium">{{ $file?->getClientOriginalName() }}</span>
                            @if ($definition->requiresCampus() && $campus)
                                · Campus <span class="font-medium" data-testid="import-campus">{{ $campus->code }} · {{ $campus->name }}</span>
                            @endif
                        </p>

                        <x-select
                            label="Mapeo de columnas"
                            name="mappingId"
                            wire:model.live="mappingId"
                            :options="$mappings"
                            hint="Por nombre reconoce las columnas que se llaman como el campo, sin importar mayúsculas ni acentos."
                        />

                        @if ($absentColumns !== [])
                            <x-alert
                                :title="'El mapeo «'.$selectedMapping->name.'» espera columnas que el archivo no trae: '.implode(', ', $absentColumns).'.'"
                                description="Sus campos se quedan sin datos. Si el SII cambió la exportación, ajusta las columnas y guárdalas como un mapeo nuevo."
                                icon="o-exclamation-triangle"
                                class="alert-warning"
                            />
                        @endif

                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Campo de Nexus</th>
                                        <th>Columna del archivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($definition->fields() as $field => $label)
                                        <tr wire:key="field-{{ $field }}">
                                            <td>
                                                {{ $label }}
                                                @if (in_array($field, $definition->naturalKey(), true))
                                                    <x-badge value="Clave" class="badge-primary badge-soft badge-sm" />
                                                @endif
                                            </td>
                                            <td>
                                                <x-select
                                                    wire:model.live="assignments.{{ $field }}"
                                                    :options="$columnOptions"
                                                    aria-label="Columna para {{ $label }}"
                                                    data-testid="column-{{ $field }}"
                                                />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @error('assignments')
                            <x-alert :title="$message" icon="o-x-circle" class="alert-error" />
                        @enderror

                        <x-input
                            label="Nombre del mapeo"
                            name="mappingName"
                            wire:model="mappingName"
                            placeholder="Por ejemplo: Exportación de escolares 2026"
                            hint="Solo si cambias la propuesta: se guarda para importar así la próxima vez."
                        />

                        @foreach (['template', 'file', 'campus', 'mapping'] as $key)
                            @error($key)
                                <x-alert :title="$message" icon="o-x-circle" class="alert-error" />
                            @enderror
                        @endforeach

                        <x-slot:actions>
                            <x-button label="Volver" icon="o-arrow-left" wire:click="back" />
                            <x-button label="Validar el archivo" type="submit" icon="o-check" class="btn-primary" spinner="upload" />
                        </x-slot:actions>
                    </x-form>
                </x-card>
            @endif
        </x-step>

        <x-step step="3" text="Validación" />
        <x-step step="4" text="Aplicación" />
    </x-steps>

    @if ($batch)
        <div @if ($batch->status->inProgress()) wire:poll.2s @endif data-testid="batch-status">
            <x-card :title="$batch->original_name" :subtitle="$batchTemplate" shadow separator>
                <x-slot:menu>
                    <x-badge :value="$batch->status->label()" :class="$batch->status->badgeClass()" />
                </x-slot:menu>

                @if ($batch->status === BatchStatus::Uploaded)
                    <x-progress class="progress-info h-1" indeterminate />
                    <p class="mt-3 text-sm">En la cola: la validación empieza en cuanto se libere el proceso.</p>
                @elseif ($batch->status === BatchStatus::Validating)
                    <x-progress class="progress-info h-1" indeterminate />
                    <p class="mt-3 text-sm">Validando… {{ number_format($batch->processed_rows) }} filas leídas.</p>
                @elseif ($batch->status === BatchStatus::Validated)
                    <p class="text-sm">{{ number_format($batch->valid_rows) }} de {{ number_format($batch->total_rows) }} filas válidas; {{ number_format($batch->error_rows) }} con error.</p>
                @elseif ($batch->status === BatchStatus::Applying)
                    <x-progress class="progress-primary h-1" :value="$batch->processed_rows" :max="max($batch->valid_rows, 1)" />
                    <p class="mt-3 text-sm">Aplicando… {{ number_format($batch->processed_rows) }} de {{ number_format($batch->valid_rows) }} filas.</p>
                @elseif ($batch->status === BatchStatus::Applied)
                    <p class="text-sm">Aplicado: {{ number_format($batch->created_rows) }} altas, {{ number_format($batch->updated_rows) }} cambios y {{ number_format($batch->unchanged_rows) }} sin cambios.</p>
                @else
                    <x-alert :title="$batch->failure_reason" icon="o-x-circle" class="alert-error" />
                @endif

                <x-slot:actions>
                    <x-button label="Importar otro archivo" icon="o-arrow-up-tray" wire:click="startOver" />
                </x-slot:actions>
            </x-card>
        </div>
    @endif
</div>
