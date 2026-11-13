<?php

namespace App\Modules\Core\Queries;

/**
 * Cómo se muestra un nombramiento: el puesto (`title`), el alcance en etiquetas cortas (`chips`) y el renglón de una
 * línea (`summary`), p. ej. «Coordinador de carrera · ISC · Presencial · Campus II» o «Jefe de proyecto docente ·
 * Sistemas y Computación · Presencial · Todos los campus». Además da el alcance por separado para las tablas: la carrera
 * (código y nombre), la unidad, la modalidad y el campus («Todos los campus» si el puesto lo admite y no lo fija).
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
        public ?string $careerCode = null,
        public ?string $careerName = null,
        public ?string $unitName = null,
        public ?string $modalityName = null,
        public ?string $campusName = null,
    ) {}
}
