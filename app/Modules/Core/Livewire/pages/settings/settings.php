<?php

use App\Modules\Core\Actions\ResetSetting;
use App\Modules\Core\Actions\UpdateSetting;
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

        return [
            'modules' => $settings->modules(),
            'canUpdate' => Gate::allows('update', Setting::class),
        ];
    }

    public function save(string $module, SettingsQuery $settings, SettingRegistry $registry, UpdateSetting $update): void
    {
        $this->authorize('update', Setting::class);
        $definitions = $registry->byModule()[$module] ?? abort(404);

        $this->resetErrorBag();
        $valid = [];

        foreach ($definitions as $definition) {
            $id = $this->idOf($definition);

            try {
                $definition->validated($this->values[$id] ?? null);
                $valid[] = $definition;
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $this->addError('values.'.$id.substr($field, strlen('value')), $messages[0]);
                }
            }
        }

        if (count($valid) !== count($definitions)) {
            $this->error('Revisa los valores marcados', 'No se guardó nada de este módulo.');

            return;
        }

        foreach ($valid as $definition) {
            $update->handle($definition, $this->values[$this->idOf($definition)] ?? null);
        }

        $this->loadValues($settings);
        $this->success('Parámetros guardados', e($definitions[0]->moduleLabel));
    }

    public function resetToDefault(string $key, SettingsQuery $settings, SettingRegistry $registry, ResetSetting $reset): void
    {
        $this->authorize('update', Setting::class);
        $definition = $registry->find($key) ?? abort(404);

        $reset->handle($definition);

        $this->resetErrorBag();
        $this->loadValues($settings);
        $this->success('Valor por omisión', e($definition->label));
    }

    /**
     * La llave sin puntos, para usarla en el nombre de un campo.
     */
    public function idOf(SettingDefinition $definition): string
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
