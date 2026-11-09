<?php

namespace App\Modules\Core\Demo;

use App\Modules\Core\Enums\DemoCheckpoint;
use Closure;

/**
 * Lo que debe ser cierto al llegar a cada punto de `$at` («5: cero choques duros»). `$passes` devuelve `true` o el
 * motivo del fallo; es un conteo barato. La declara el módulo que lo garantiza, en `demoChecks()`.
 */
final readonly class DemoCheck
{
    /**
     * @param  list<DemoCheckpoint>  $at
     * @param  Closure(DemoContext): (true|string)  $passes
     */
    public function __construct(
        public array $at,
        public string $label,
        public Closure $passes,
    ) {}
}
