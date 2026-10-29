@use('App\Modules\Imports\Enums\BatchStatus')

<div>
    <x-header title="Importaciones" subtitle="Carga un catálogo desde una exportación del SII: sube el archivo, revisa cada fila y aplica." separator progress-indicator />

    <x-steps wire:model="step" steps-color="step-primary" stepper-classes="w-full mb-6">
        <x-step step="1" text="Archivo">
            @if ($currentStep === 1)
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

                                <x-file label="Archivo" name="file" wire:model="file" accept=".xlsx,.csv,.txt" hint="Exportación del SII en .xlsx, .csv o .txt, hasta 19 MB." />
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

                        @scope('cell_template', $recentBatch, $templateLabels)
                            {{ $templateLabels[$recentBatch->template] ?? $recentBatch->template }}
                        @endscope

                        @scope('cell_campus', $recentBatch)
                            {{ $recentBatch->campus?->code ?? '—' }}
                        @endscope

                        @scope('cell_status', $recentBatch)
                            <x-core::status-badge :tone="$recentBatch->status->tone()" :label="$recentBatch->status->label()" :icon="$recentBatch->status->icon()" />
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
            @if ($currentStep === 2 && $definition)
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
                                                    wire:model="assignments.{{ $field }}"
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
        <div @if ($polling) wire:poll.2s @endif data-testid="batch-status">
            <x-card :title="$batch->original_name" :subtitle="$batchTemplate" shadow separator>
                <x-slot:menu>
                    <x-core::status-badge :tone="$batch->status->tone()" :label="$batch->status->label()" :icon="$batch->status->icon()" />
                </x-slot:menu>

                @if ($batch->status === BatchStatus::Uploaded)
                    <x-progress class="progress-info h-1" indeterminate />
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm">En la cola: la validación empieza en cuanto se libere el proceso.</p>
                        <x-button
                            label="Validar de nuevo"
                            icon="o-arrow-path"
                            class="btn-ghost btn-sm"
                            wire:click="requeue"
                            spinner="requeue"
                            tooltip-left="Si lleva mucho en la cola o la cola no respondió al subirlo."
                        />
                    </div>
                @elseif ($batch->status === BatchStatus::Validating)
                    <x-progress class="progress-info h-1" indeterminate />
                    <p class="mt-3 text-sm">Validando… {{ number_format($batch->processed_rows) }} filas leídas.</p>
                @elseif ($batch->status === BatchStatus::Applying)
                    <x-progress class="progress-primary h-1" :value="$batch->processed_rows" :max="max($batch->valid_rows, 1)" />
                    <p class="mt-3 text-sm">Aplicando… {{ number_format($batch->processed_rows) }} de {{ number_format($batch->valid_rows) }} filas.</p>
                @elseif ($batch->status === BatchStatus::Failed)
                    <x-alert :title="$batch->failure_reason" icon="o-x-circle" class="alert-error" />
                @endif

                @if ($applyQueued)
                    <x-progress class="progress-primary h-1" indeterminate />
                    <p class="mt-3 mb-4 text-sm">En la cola: la aplicación empieza en cuanto se libere el proceso.</p>
                @endif

                @if ($currentStep === 3 && $batch->status === BatchStatus::Validated)
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <x-stat title="Filas" :value="number_format($batch->total_rows)" icon="o-table-cells" />
                        <x-stat title="Válidas" :value="number_format($batch->valid_rows)" icon="o-check-circle" />
                        <x-stat title="Con advertencia" :value="number_format($warningRows)" icon="o-exclamation-triangle" />
                        <x-stat title="Con error" :value="number_format($batch->error_rows)" icon="o-x-circle" />
                    </div>
                @elseif ($applied !== null)
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <x-stat title="Altas" :value="number_format($applied['created'])" icon="o-plus-circle" />
                        <x-stat title="Cambios" :value="number_format($applied['updated'])" icon="o-pencil-square" />
                        <x-stat title="Sin cambios" :value="number_format($applied['unchanged'])" icon="o-minus-circle" />
                        <x-stat title="Con error, sin aplicar" :value="number_format($applied['skipped'])" icon="o-x-circle" />
                    </div>
                @endif

                @if ($issueRows !== null)
                    <div class="mt-6 flex flex-wrap items-end justify-between gap-4">
                        <x-select label="Filas" wire:model.live="issues" :options="$issueKinds" class="w-56" />

                        @if ($errorRows === 0)
                            <span class="text-sm text-base-content/70">Ninguna fila trae error.</span>
                        @elseif ($errorsReady)
                            <x-button
                                label="Descargar errores"
                                icon="o-arrow-down-tray"
                                :link="route('imports.batch-errors', $batch->id)"
                                external
                                no-wire-navigate
                                class="btn-outline"
                                tooltip-left="Las filas con error como vinieron, para corregirlas y volver a subir el archivo."
                            />
                        @elseif ($errorsLost)
                            <span class="text-sm text-base-content/70">No se pudo preparar el archivo de errores. Avisa al equipo de Nexus.</span>
                        @else
                            <span class="text-sm text-base-content/70">Preparando el archivo de errores…</span>
                        @endif
                    </div>

                    <x-table
                        :headers="[
                            ['key' => 'row_number', 'label' => 'Fila', 'class' => 'w-24'],
                            ['key' => 'key', 'label' => 'Clave', 'sortable' => false],
                            ['key' => 'messages', 'label' => 'Mensajes', 'sortable' => false],
                        ]"
                        :rows="$issueRows"
                        with-pagination
                        class="mt-2"
                    >
                        @scope('cell_row_number', $row)
                            Fila {{ $row->row_number }}
                        @endscope

                        @scope('cell_key', $row, $batchKey)
                            {{ implode(' · ', array_map(fn (string $field): string => is_scalar($value = $row->normalized[$field] ?? '—') ? (string) $value : json_encode($value), $batchKey)) }}
                        @endscope

                        @scope('cell_messages', $row, $batchFields)
                            <ul class="space-y-1">
                                @foreach ($row->errors as $field => $messages)
                                    <li class="flex items-start gap-2">
                                        <x-core::status-badge tone="error" label="Error" class="shrink-0" />
                                        <span>{{ $batchFields[$field] ?? $field }}: {{ implode(' ', $messages) }}</span>
                                    </li>
                                @endforeach
                                @foreach ($row->warnings as $field => $messages)
                                    <li class="flex items-start gap-2">
                                        <x-core::status-badge tone="warning" label="Advertencia" icon="o-exclamation-triangle" class="shrink-0" />
                                        <span>{{ $batchFields[$field] ?? $field }}: {{ implode(' ', $messages) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endscope

                        <x-slot:empty>
                            <x-icon name="o-check-circle" label="Ninguna fila en esta lista." />
                        </x-slot:empty>
                    </x-table>
                @endif

                <x-slot:actions>
                    <x-button label="Importar otro archivo" icon="o-arrow-up-tray" wire:click="startOver" />

                    @if ($currentStep === 3 && $batch->status === BatchStatus::Validated && ! $applyQueued)
                        @if ($batch->valid_rows > 0)
                            <x-button
                                :label="$batch->valid_rows === 1 ? 'Aplicar 1 fila' : 'Aplicar '.number_format($batch->valid_rows).' filas'"
                                icon="o-check"
                                class="btn-primary"
                                wire:click="apply"
                                spinner="apply"
                                wire:confirm="Se escribirán {{ number_format($batch->valid_rows) }} filas en {{ $batchTemplate }}.{{ $batch->error_rows > 0 ? ' Las '.number_format($batch->error_rows).' filas con error se saltan.' : '' }}"
                            />
                        @else
                            <span class="tooltip tooltip-left" data-tip="No hay filas válidas que aplicar.">
                                <x-button label="Aplicar" icon="o-check" class="btn-primary" disabled />
                            </span>
                        @endif
                    @endif
                </x-slot:actions>
            </x-card>
        </div>
    @endif
</div>
