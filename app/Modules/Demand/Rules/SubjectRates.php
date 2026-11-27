<?php

namespace App\Modules\Demand\Rules;

use App\Modules\Demand\Enums\GapBucket;
use InvalidArgumentException;

/**
 * Las tasas históricas de una materia en un campus (tarea 5.2), de 0 a 1: la de no inscripción (elegibles al inicio del
 * periodo que no la cursaron) y la de reprobación (la cursaron y no la aprobaron), sobre los últimos periodos del mismo
 * tipo (`demand.history_terms`, 4). `termsUsed` es cuántos periodos tenían datos; `fromDepartment`, que la materia no
 * tenía historia y se usó el promedio de su departamento.
 *
 * `nonEnrollmentByGap` es la no inscripción de cada tramo de distancia con elegibles (GapBucket; DM-03, confirmado el
 * 17 de noviembre de 2026); un tramo que no está usa `nonEnrollment`, la de todos los tramos.
 */
final readonly class SubjectRates
{
    /**
     * @param  array<string, float>  $nonEnrollmentByGap  valor de GapBucket → no inscripción de ese tramo
     */
    public function __construct(
        public float $nonEnrollment,
        public float $failure,
        public int $termsUsed,
        public bool $fromDepartment = false,
        public array $nonEnrollmentByGap = [],
    ) {
        $rates = ['nonEnrollment' => $nonEnrollment, 'failure' => $failure];

        foreach ($nonEnrollmentByGap as $bucket => $rate) {
            if (GapBucket::tryFrom((string) $bucket) === null) {
                throw new InvalidArgumentException("Tramo de distancia desconocido: {$bucket}.");
            }

            $rates["nonEnrollment[{$bucket}]"] = $rate;
        }

        foreach ($rates as $name => $rate) {
            if ($rate < 0.0 || $rate > 1.0) {
                throw new InvalidArgumentException("La tasa {$name} va de 0 a 1: {$rate}.");
            }
        }

        if ($termsUsed < 0) {
            throw new InvalidArgumentException("Los periodos de historia no son negativos: {$termsUsed}.");
        }
    }

    /**
     * La no inscripción de un tramo de distancia, o la de todos los tramos si ese no tuvo elegibles.
     */
    public function nonEnrollmentFor(GapBucket $bucket): float
    {
        return $this->nonEnrollmentByGap[$bucket->value] ?? $this->nonEnrollment;
    }
}
