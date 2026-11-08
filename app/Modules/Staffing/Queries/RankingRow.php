<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Faculty\Enums\QualificationLevel;

/**
 * Un lugar del ranking de un grupo como lo muestra la asignación: el docente, su plaza y el desglose guardado en
 * `staffing_rankings.breakdown` (reglas-de-negocio.md, «Ranking docente (desempate)»).
 */
final readonly class RankingRow
{
    /** Las marcas que bajan al docente al bloque final del ranking, con su nombre en pantalla. */
    public const array MARKS = ['sin_horas' => 'Sin horas', 'choque' => 'Choque', 'no_disponible' => 'No disponible'];

    /**
     * @param  list<string>  $flags  `sin_horas`, `choque`, `no_disponible`, `sin_evaluacion`
     */
    public function __construct(
        public int $rank,
        public float $score,
        public string $teacherId,
        public string $teacherName,
        public string $positions,
        public int $baseHours,
        public int $historyCount,
        public ?QualificationLevel $qualification,
        public ?float $evaluation,
        public ?int $remainingHours,
        public array $flags,
        public bool $fromOtherDepartment,
    ) {}

    /**
     * Si va en el bloque final: sin horas, choque o no disponible.
     */
    public function isFlagged(): bool
    {
        return $this->marks() !== [];
    }

    /**
     * Las marcas del bloque final que tiene, por su clave, con su nombre.
     *
     * @return array<string, string>
     */
    public function marks(): array
    {
        return array_intersect_key(self::MARKS, array_flip($this->flags));
    }

    public function lacksEvaluation(): bool
    {
        return $this->evaluation === null;
    }
}
