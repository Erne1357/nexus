<?php

namespace App\Modules\Core\Demo;

use App\Modules\Core\Enums\DemoCheckpoint;
use Closure;

/**
 * Un paso de la demo por puntos: trabajo de un módulo que **lleva a** `$reaches` terminando la etapa anterior (validar
 * todos los catálogos lleva a «propuesta»). `$run` recibe el `DemoContext`; lo declara el provider del módulo en
 * `demoSteps()`.
 */
final readonly class DemoStep
{
    /**
     * @param  Closure(DemoContext): void  $run
     */
    public function __construct(
        public DemoCheckpoint $reaches,
        public string $label,
        public Closure $run,
    ) {}
}
