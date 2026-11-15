<?php

namespace App\Modules\Core\Settings;

use App\Modules\Core\ModuleServiceProvider;
use LogicException;

/**
 * Catálogo de los parámetros que declaran los módulos en settings() de su provider (CF-S7). Se valida al armarlo: una
 * llave repetida o un valor por omisión que su propia definición no acepta es un LogicException, así que un catálogo mal
 * armado falla al arrancar.
 *
 * Core lo arma una vez y lo comparte entre peticiones (Octane): es inmutable y no guarda nada del usuario ni de la
 * petición. El orden es el de los módulos y, dentro, el de su declaración.
 */
final class SettingRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $byKey = [];

    /**
     * @param  list<SettingDefinition>  $definitions
     */
    private function __construct(array $definitions)
    {
        foreach ($definitions as $definition) {
            if (isset($this->byKey[$definition->key])) {
                throw new LogicException("La llave de parámetro «{$definition->key}» está repetida (módulos {$this->byKey[$definition->key]->module} y {$definition->module}).");
            }

            if (! $definition->accepts($definition->default)) {
                throw new LogicException("El valor por omisión del parámetro «{$definition->key}» no pasa su propia validación.");
            }

            $this->byKey[$definition->key] = $definition;
        }
    }

    /**
     * @param  iterable<ModuleServiceProvider>  $modules
     */
    public static function fromModules(iterable $modules): self
    {
        $definitions = [];

        foreach ($modules as $module) {
            array_push($definitions, ...$module->settings());
        }

        return new self($definitions);
    }

    /**
     * @param  list<SettingDefinition>  $definitions
     */
    public static function fromDefinitions(array $definitions): self
    {
        return new self($definitions);
    }

    public function find(string $key): ?SettingDefinition
    {
        return $this->byKey[$key] ?? null;
    }

    /**
     * @throws LogicException si ningún módulo declaró la llave
     */
    public function get(string $key): SettingDefinition
    {
        return $this->byKey[$key] ?? throw new LogicException("El parámetro «{$key}» no lo declaró ningún módulo.");
    }

    /**
     * @return list<SettingDefinition>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    /**
     * Las definiciones por módulo, en el orden de declaración: alias => definiciones.
     *
     * @return array<string, list<SettingDefinition>>
     */
    public function byModule(): array
    {
        $groups = [];

        foreach ($this->byKey as $definition) {
            $groups[$definition->module][] = $definition;
        }

        return $groups;
    }
}
