<?php

namespace App\Modules\Scheduling\Rules\Soft;

/**
 * El puntaje blando de una solución (tarea 6.5): el total, la penalización con peso de cada regla (`byRule`, que suma
 * el total) y sin peso, en la unidad de cada regla (`units`). Las dos listas traen SC-01 a SC-09 en orden. Menor es
 * mejor.
 */
final readonly class SoftResult
{
    /**
     * @param  array<string, int>  $byRule
     * @param  array<string, int>  $units
     */
    public function __construct(
        public int $total,
        public array $byRule,
        public array $units,
    ) {}
}
