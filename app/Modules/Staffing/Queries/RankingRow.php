<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Faculty\Enums\QualificationLevel;

/**
 * Un lugar del ranking de un grupo como lo muestra la asignación: el docente, su plaza y el desglose guardado en
 * `staffing_rankings.breakdown` (reglas-de-negocio.md, «Ranking docente (desempate)»). `remainingHours` son las horas
 * que le quedan al docente sin el grupo y `remainingAfterHours`, las que le quedarían con él; las dos pueden faltar
 * (null) en renglones viejos o cuando el validador no las da.
 */
final readonly class RankingRow
{
    /** Las marcas que bajan al docente al bloque final del ranking, con su nombre en pantalla. */
    public const array MARKS = ['sin_horas' => 'Sin horas', 'choque' => 'Choque', 'no_disponible' => 'No disponible'];

    /** Los avisos que se muestran discretos sin mover al docente de lugar (SC-09), con su nombre en pantalla. */
    public const array NOTICES = ['traslado_justo' => 'traslado justo'];

    /**
     * @param  list<string>  $flags  `sin_horas`, `choque`, `no_disponible`, `sin_evaluacion`, `traslado_justo`
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
        public ?int $remainingAfterHours,
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

    /**
     * Los avisos discretos que tiene (traslado justo, SC-09), por su clave, con su nombre.
     *
     * @return array<string, string>
     */
    public function notices(): array
    {
        return array_intersect_key(self::NOTICES, array_flip($this->flags));
    }

    public function lacksEvaluation(): bool
    {
        return $this->evaluation === null;
    }

    /**
     * El puntaje sin ceros de sobra: «15», «9.66».
     */
    public function scoreLabel(): string
    {
        return rtrim(rtrim(number_format($this->score, 2, '.', ''), '0'), '.');
    }

    /**
     * La evaluación con un decimal, o null si no tiene.
     */
    public function evaluationLabel(): ?string
    {
        return $this->evaluation === null ? null : number_format($this->evaluation, 1, '.', '');
    }

    /**
     * Las horas que le quedan sin el grupo y, si se saben, con él: «12 → 7» o «12»; null si no se saben.
     */
    public function remainingLabel(): ?string
    {
        if ($this->remainingHours === null) {
            return null;
        }

        return $this->remainingAfterHours === null ? (string) $this->remainingHours : "{$this->remainingHours} → {$this->remainingAfterHours}";
    }
}
