<?php

namespace App\Modules\Students\Queries;

use App\Modules\Students\Enums\SubjectStanding;

/**
 * Una materia en la retícula de la ficha del alumno (StudentRecord): su estado como lo nombra el SII, la
 * calificación y el periodo en que la acreditó o la cursa, su seriación y lo que le falta para cursarla.
 */
final readonly class GridCell
{
    /**
     * @param  list<string>  $prerequisites  claves de la seriación que pide en el plan
     * @param  list<string>  $missing  claves de esa seriación que todavía no acredita
     * @param  int|null  $creditShare  porcentaje de los créditos del plan que pide, si pide alguno
     * @param  bool  $beyondReach  si está más allá de su semestre y dos más
     * @param  int|null  $chain  su cadena de seriación (StudentRecord::$chains), si está en alguna
     */
    public function __construct(
        public string $subjectId,
        public string $code,
        public string $name,
        public int $credits,
        public int $semester,
        public ?string $specialty,
        public SubjectStanding $standing,
        public ?string $grade,
        public ?string $termCode,
        public ?int $attempt,
        public array $prerequisites,
        public array $missing,
        public ?int $creditShare,
        public bool $beyondReach,
        public ?int $chain,
    ) {}
}
