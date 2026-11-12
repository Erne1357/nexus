<?php

namespace App\Modules\Core\Queries;

/**
 * Cómo se muestra un nombramiento: el puesto (`title`), el alcance en etiquetas cortas (`chips`) y el renglón de una
 * línea (`summary`), p. ej. «Coordinador de carrera · ISC · Presencial · Campus II» o «Jefe de proyecto docente ·
 * Sistemas y Computación · Presencial · Todos los campus».
 */
final readonly class AssignmentLabel
{
    /**
     * @param  list<array{label: string, value: string}>  $chips
     */
    public function __construct(
        public string $title,
        public array $chips,
        public string $summary,
    ) {}
}
