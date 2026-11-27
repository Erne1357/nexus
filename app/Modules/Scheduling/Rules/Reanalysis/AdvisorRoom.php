<?php

namespace App\Modules\Scheduling\Rules\Reanalysis;

/**
 * Un salón para ReanalysisAdvisor: su clave, su capacidad (HC-05) y, si es de otro departamento, el nombre del dueño
 * a quien se pide el préstamo (HC-10).
 */
final readonly class AdvisorRoom
{
    public function __construct(
        public string $id,
        public string $code,
        public int $capacity,
        public ?string $loanFrom = null,
    ) {}
}
