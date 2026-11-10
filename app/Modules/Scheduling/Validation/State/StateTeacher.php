<?php

namespace App\Modules\Scheduling\Validation\State;

/**
 * Un docente en el estado del validador: horas base y externas del periodo (HC-08) y sus bloques no disponibles
 * (HC-09), cada uno como [día, inicio, fin] en minutos.
 */
final readonly class StateTeacher
{
    /**
     * @param  list<array{int, int, int}>  $unavailable
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $baseHours = 0,
        public int $externalHours = 0,
        public array $unavailable = [],
        public bool $isHonorarios = false,
    ) {}
}
