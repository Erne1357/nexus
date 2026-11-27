<?php

namespace App\Modules\Core\Demo;

use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\ModuleServiceProvider;

/**
 * Los pasos, revisiones y eventos silenciados de la demo por puntos de todos los módulos, en el orden de
 * bootstrap/providers.php (el del mapa de dependencias) y, dentro de cada módulo, en el de su lista. Como Navigation,
 * Core no conoce a ningún módulo: cada uno se declara en su provider.
 */
final readonly class DemoPlan
{
    /**
     * @param  list<DemoStep>  $steps
     * @param  list<DemoCheck>  $checks
     * @param  list<class-string>  $quietEvents
     */
    public function __construct(
        private array $steps = [],
        private array $checks = [],
        private array $quietEvents = [],
    ) {}

    /**
     * @param  iterable<ModuleServiceProvider>  $modules
     */
    public static function fromModules(iterable $modules): self
    {
        $steps = $checks = $quietEvents = [];

        foreach ($modules as $module) {
            array_push($steps, ...$module->demoSteps());
            array_push($checks, ...$module->demoChecks());
            array_push($quietEvents, ...$module->demoQuietEvents());
        }

        return new self($steps, $checks, array_values(array_unique($quietEvents)));
    }

    /**
     * Los pasos que llevan a `$checkpoint`, en orden.
     *
     * @return list<DemoStep>
     */
    public function stepsFor(DemoCheckpoint $checkpoint): array
    {
        return array_values(array_filter($this->steps, fn (DemoStep $step): bool => $step->reaches === $checkpoint));
    }

    /**
     * Las revisiones que valen en `$checkpoint`.
     *
     * @return list<DemoCheck>
     */
    public function checksAt(DemoCheckpoint $checkpoint): array
    {
        return array_values(array_filter($this->checks, fn (DemoCheck $check): bool => in_array($checkpoint, $check->at, true)));
    }

    /**
     * @return list<class-string>
     */
    public function quietEvents(): array
    {
        return $this->quietEvents;
    }
}
