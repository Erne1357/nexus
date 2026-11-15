<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingRegistry;

/**
 * Lo que muestra la pantalla «Parámetros» (CF-S7): por módulo, cada parámetro declarado con su valor actual (el de la fila
 * global, o el de omisión si no hay o no pasa la validación de su definición), su valor por omisión y si se cambió.
 */
final class SettingsQuery
{
    public function __construct(private readonly SettingRegistry $registry) {}

    /**
     * @return list<array{module: string, label: string, settings: list<array{definition: SettingDefinition, current: mixed, default: mixed, changed: bool, invalid: bool}>}>
     */
    public function modules(): array
    {
        $stored = Setting::query()
            ->where('scope', SettingScope::Global)->whereNull('scope_id')
            ->whereIn('key', array_map(fn (SettingDefinition $definition): string => $definition->key, $this->registry->all()))
            ->pluck('value', 'key')
            ->all();

        $modules = [];

        foreach ($this->registry->byModule() as $module => $definitions) {
            $modules[] = [
                'module' => $module,
                'label' => $definitions[0]->moduleLabel,
                'settings' => array_map(function (SettingDefinition $definition) use ($stored): array {
                    $has = array_key_exists($definition->key, $stored);
                    $valid = $has && $definition->accepts($stored[$definition->key]);
                    $current = $valid ? $stored[$definition->key] : $definition->default;

                    return [
                        'definition' => $definition,
                        'current' => $current,
                        'default' => $definition->default,
                        'changed' => $valid && $current != $definition->default,
                        'invalid' => $has && ! $valid,
                    ];
                }, $definitions),
            ];
        }

        return $modules;
    }
}
