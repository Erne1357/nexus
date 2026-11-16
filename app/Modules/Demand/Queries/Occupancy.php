<?php

namespace App\Modules\Demand\Queries;

/**
 * Ocupación de una materia en un periodo (y un campus): los alumnos distintos que la tomaron (resultado aprobada,
 * reprobada o cursando; la `baja` NO cuenta, decisión del 10 de noviembre de 2026: «quien tiene la materia ese
 * periodo, aprobada, reprobada o cursando, la tomó») y, de ellos, cuántos la aprobaron, la reprobaron y todavía
 * la cursan.
 */
final readonly class Occupancy
{
    public function __construct(
        public int $students,
        public int $passed,
        public int $failed,
        public int $inProgress,
    ) {}
}
