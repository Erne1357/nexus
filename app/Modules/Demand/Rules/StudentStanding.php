<?php

namespace App\Modules\Demand\Rules;

use App\Modules\Demand\Enums\GapBucket;

/**
 * Lo que un alumno puede cursar al inicio de un periodo (StandingCalculator): las materias para las que es elegible,
 * cuáles de ellas lo son solo porque cuenta como aprobado lo que cursa (`pendingPrerequisite`), lo que cursa
 * (`taking`), su semestre estimado y, de cada materia elegible, su distancia a ese semestre (`gapOf`, DM-03).
 */
final readonly class StudentStanding
{
    /**
     * @param  list<string>  $eligible  ids de materia
     * @param  list<string>  $pendingPrerequisite  ids de materia, parte de `$eligible`
     * @param  list<string>  $taking  ids de materia
     * @param  array<string, GapBucket>  $gapOf  id de materia elegible → su tramo de distancia, en el orden de `$eligible`
     */
    public function __construct(
        public array $eligible,
        public array $pendingPrerequisite,
        public array $taking,
        public int $semester,
        public array $gapOf = [],
    ) {}
}
