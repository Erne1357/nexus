<?php

namespace App\Modules\Demand\Rules;

use InvalidArgumentException;

/**
 * Las tasas históricas de una materia en un campus (tarea 5.2), de 0 a 1: la de no inscripción (elegibles al inicio del
 * periodo que no la cursaron) y la de reprobación (la cursaron y no la aprobaron), sobre los últimos periodos del mismo
 * tipo (`demand.history_terms`, 4). `termsUsed` es cuántos periodos tenían datos; `fromDepartment`, que la materia no
 * tenía historia y se usó el promedio de su departamento.
 */
final readonly class SubjectRates
{
    public function __construct(
        public float $nonEnrollment,
        public float $failure,
        public int $termsUsed,
        public bool $fromDepartment = false,
    ) {
        foreach (['nonEnrollment' => $nonEnrollment, 'failure' => $failure] as $name => $rate) {
            if ($rate < 0.0 || $rate > 1.0) {
                throw new InvalidArgumentException("La tasa {$name} va de 0 a 1: {$rate}.");
            }
        }

        if ($termsUsed < 0) {
            throw new InvalidArgumentException("Los periodos de historia no son negativos: {$termsUsed}.");
        }
    }
}
