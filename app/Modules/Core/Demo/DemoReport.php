<?php

namespace App\Modules\Core\Demo;

use App\Modules\Core\Enums\DemoCheckpoint;

/**
 * Lo que dejó una corrida de la demo por puntos: cuánto tardó cada paso y qué revisiones pasaron.
 */
final readonly class DemoReport
{
    /**
     * @param  list<array{checkpoint: DemoCheckpoint, label: string, seconds: float}>  $steps
     * @param  list<array{checkpoint: DemoCheckpoint, label: string, passed: bool, reason: ?string}>  $checks
     */
    public function __construct(
        public array $steps,
        public array $checks,
    ) {}

    public function failed(): bool
    {
        foreach ($this->checks as $check) {
            if (! $check['passed']) {
                return true;
            }
        }

        return false;
    }
}
