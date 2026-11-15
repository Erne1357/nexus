{{--
    «Parámetros» (core::pages.settings): una tarjeta por módulo con sus parámetros. Cada parámetro dice qué cambia
    (etiqueta y ayuda), tiene su campo según el tipo (número, decimal, sí o no, o una tabla de valores con nombre) y, debajo,
    su valor por omisión y «Volver al valor por omisión» (con confirmación corta, ADR-0023) si se cambió. «Guardar» es
    por tarjeta. Quien solo puede ver, ve los campos deshabilitados.
--}}
<div>
    <x-header title="Parámetros" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Los valores que usan los módulos para calcular y avisar. Un cambio aplica de inmediato y queda en la bitácora.
        </x-slot:subtitle>
    </x-header>

    <div class="grid gap-6 xl:grid-cols-2" data-testid="settings-modules">
        @foreach ($modules as $module)
            <section wire:key="module-{{ $module['module'] }}" class="rounded-box border border-base-300 bg-base-100" aria-labelledby="module-{{ $module['module'] }}-title" data-testid="settings-{{ $module['module'] }}">
                <header class="border-b border-base-300 px-5 py-3">
                    <h2 id="module-{{ $module['module'] }}-title" class="text-lg font-bold">{{ $module['label'] }}</h2>
                </header>

                <form wire:submit="save('{{ $module['module'] }}')" class="divide-y divide-base-300">
                    @foreach ($module['settings'] as $row)
                        @php
                            $definition = $row['definition'];
                            $id = str_replace('.', '__', $definition->key);
                            $isTable = $definition->type->isTable();
                            $step = in_array($definition->type, [\App\Modules\Core\Settings\SettingType::Decimal, \App\Modules\Core\Settings\SettingType::DecimalTable], true) ? 'any' : '1';
                        @endphp
                        <div wire:key="setting-{{ $id }}" class="space-y-3 px-5 py-4" data-testid="setting-{{ $definition->key }}">
                            <div>
                                <h3 class="font-semibold">{{ $definition->label }}</h3>
                                <p class="text-sm text-neutral">{{ $definition->help }}</p>
                            </div>

                            @if ($row['invalid'])
                                <x-alert icon="o-exclamation-triangle" class="alert-warning" title="El valor guardado no es válido; se usa el de omisión. Guarda uno nuevo para repararlo." />
                            @endif

                            @if ($isTable)
                                <div class="grid gap-3 sm:grid-cols-2">
                                    @foreach ($definition->fields as $field => $label)
                                        <x-input :label="$label" :name="'values.'.$id.'.'.$field" wire:model="values.{{ $id }}.{{ $field }}" type="number" :step="$step" :suffix="$definition->unit" :disabled="! $canUpdate" class="font-mono" />
                                    @endforeach
                                </div>
                            @elseif ($definition->type === \App\Modules\Core\Settings\SettingType::Boolean)
                                <x-toggle :label="$definition->label" wire:model="values.{{ $id }}" :disabled="! $canUpdate" />
                            @else
                                <div class="max-w-xs">
                                    <x-input :label="$definition->label" :name="'values.'.$id" wire:model="values.{{ $id }}" type="number" :step="$step" :suffix="$definition->unit" :placeholder="$definition->nullable ? 'Sin valor' : null" :disabled="! $canUpdate" class="font-mono" />
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-neutral">
                                <p>Por omisión: <span class="font-medium text-base-content">{{ $definition->display($row['default']) }}</span></p>

                                @if ($row['changed'])
                                    @if ($canUpdate)
                                        <x-button
                                            label="Volver al valor por omisión"
                                            class="btn-ghost btn-sm"
                                            wire:click="resetToDefault('{{ $definition->key }}')"
                                            x-confirm="El parámetro vuelve a {{ $definition->display($row['default']) }}. El cambio queda en la bitácora."
                                            data-confirm-title="¿Volver «{{ $definition->label }}» al valor por omisión?"
                                            data-confirm-button="Volver al valor por omisión"
                                            data-confirm-tone="primary"
                                            spinner="resetToDefault('{{ $definition->key }}')"
                                            data-testid="reset-{{ $definition->key }}"
                                        />
                                    @else
                                        <span class="tooltip tooltip-left" data-tip="No tienes permiso para cambiar los parámetros.">
                                            <x-button label="Volver al valor por omisión" class="btn-ghost btn-sm" disabled />
                                        </span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endforeach

                    <div class="flex justify-end px-5 py-3">
                        @if ($canUpdate)
                            <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save('{{ $module['module'] }}')" data-testid="save-{{ $module['module'] }}" />
                        @else
                            <span class="tooltip tooltip-left" data-tip="No tienes permiso para cambiar los parámetros.">
                                <x-button label="Guardar" icon="o-check" class="btn-primary" disabled />
                            </span>
                        @endif
                    </div>
                </form>
            </section>
        @endforeach
    </div>
</div>
