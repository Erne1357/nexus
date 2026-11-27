<?php

use App\Modules\Core\Actions\ResetSetting;
use App\Modules\Core\Actions\UpdateSettings;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Queries\SettingsQuery;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * «Parámetros» (CF-S7): los valores que los módulos leen de `settings` y que el admin puede cambiar sin un despliegue, una
 * tarjeta por módulo. Cada parámetro tiene su campo según el tipo (número, decimal, sí o no, o una tabla de valores con
 * nombre), «Guardar» por tarjeta y «Volver al valor por omisión» por parámetro.
 *
 * Cada tarjeta es editable solo si el puesto puede cambiar todos sus parámetros: `core.setting.update` o el permiso propio que
 * la definición declare (la Subdirección cambia los de demanda, confirmado el 16 de noviembre de 2026). Si no, se pinta en
 * solo lectura, y «Guardar» y «Volver al valor por omisión» responden 403 aunque se llamen a mano.
 *
 * El componente lee con SettingsQuery, autoriza con SettingPolicy en mount(), en with() y en cada acción, y llama a
 * UpdateSetting y ResetSetting, que validan con la definición del módulo. «Guardar» valida toda la tarjeta antes de escribir:
 * si un campo es inválido no se guarda ninguno de esa tarjeta. Las llaves llevan punto (`staffing.weights`), que Livewire
 * toma como ruta, así que el estado de los campos usa `__` en su lugar (`values.staffing__weights.apto`).
 */
new #[Title('Parámetros')] class extends Component
{
    use Toast;

    /**
     * El valor que se está editando de cada parámetro, por la llave con `__`: el escalar o, en las tablas, campo => valor.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    public function mount(SettingsQuery $settings): void
    {
        $this->authorize('viewAny', Setting::class);
        $this->loadValues($settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(SettingsQuery $settings): array
    {
        $this->authorize('viewAny', Setting::class);

        $modules = array_map(function (array $module): array {
            $module['editable'] = collect($module['settings'])
                ->every(fn (array $row): bool => $this->canUpdate($row['definition']));

            return $module;
        }, $settings->modules());

        return ['modules' => $modules];
    }

    public function save(string $module, SettingsQuery $settings, SettingRegistry $registry, UpdateSettings $update): void
    {
        $definitions = $registry->byModule()[$module] ?? abort(404);

        foreach ($definitions as $definition) {
            $this->authorize('update', [Setting::class, $definition]);
        }

        $this->resetErrorBag();

        $values = [];

        foreach ($definitions as $definition) {
            $values[$definition->key] = $this->values[$this->idOf($definition)] ?? null;
        }

        try {
            $changed = $update->handle($definitions, $values);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('values.'.$field, $messages[0]);
            }

            $this->error('Revisa los valores marcados', 'No se guardó nada de este módulo.');

            return;
        }

        $this->loadValues($settings);

        $notices = collect($definitions)
            ->filter(fn (SettingDefinition $definition): bool => in_array($definition->key, $changed, true) && $definition->savedNotice !== null)
            ->map(fn (SettingDefinition $definition): string => $definition->savedNotice)
            ->unique()->all();

        $this->success('Parámetros guardados', implode(' ', [e($definitions[0]->moduleLabel), ...array_map(e(...), $notices)]));
    }

    public function resetToDefault(string $key, SettingsQuery $settings, SettingRegistry $registry, ResetSetting $reset): void
    {
        $definition = $registry->find($key) ?? abort(404);
        $this->authorize('update', [Setting::class, $definition]);

        $reset->handle($definition);

        $this->resetErrorBag();
        $this->loadValues($settings);
        $this->success('Valor por omisión', e($definition->label));
    }

    private function canUpdate(SettingDefinition $definition): bool
    {
        return Gate::allows('update', [Setting::class, $definition]);
    }

    /**
     * La llave sin puntos, para usarla en el nombre de un campo.
     */
    protected function idOf(SettingDefinition $definition): string
    {
        return str_replace('.', '__', $definition->key);
    }

    private function loadValues(SettingsQuery $settings): void
    {
        $this->values = [];

        foreach ($settings->modules() as $module) {
            foreach ($module['settings'] as $row) {
                $this->values[$this->idOf($row['definition'])] = $row['current'];
            }
        }
    }
};
