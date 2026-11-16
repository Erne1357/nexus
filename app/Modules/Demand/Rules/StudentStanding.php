<?php

namespace App\Modules\Demand\Rules;

/**
 * Lo que un alumno puede cursar al inicio de un periodo (StandingCalculator): las materias para las que es elegible,
 * cuáles de ellas lo son solo porque cuenta como aprobado lo que cursa (`pendingPrerequisite`), lo que cursa
 * (`taking`) y su semestre estimado.
 */
final readonly class StudentStanding
{
    /**
     * @param  list<string>  $eligible  ids de materia
     * @param  list<string>  $pendingPrerequisite  ids de materia, parte de `$eligible`
     * @param  list<string>  $taking  ids de materia
     */
    public function __construct(
        public array $eligible,
        public array $pendingPrerequisite,
        public array $taking,
        public int $semester,
    ) {}
}
