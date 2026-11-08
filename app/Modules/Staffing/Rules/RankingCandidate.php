<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Faculty\Enums\QualificationLevel;

/**
 * Un docente candidato a un grupo, con los datos que necesita el ranking (reglas-de-negocio.md, «Ranking docente
 * (desempate)»). `evaluation` es el promedio de 0 a 5 o null si no tiene; `remainingHours` es informativo. `tightTravel`
 * es el aviso de traslado justo entre campus (SC-09): se muestra, pero no lo baja al bloque final.
 */
final readonly class RankingCandidate
{
    public function __construct(
        public string $teacherId,
        public string $name,
        public int $baseHours,
        public int $historyCount,
        public ?QualificationLevel $qualification,
        public ?float $evaluation,
        public ?int $remainingHours,
        public bool $noHours,
        public bool $clash,
        public bool $unavailable,
        public ?int $previousRank,
        public bool $tightTravel = false,
    ) {}

    public function isFlagged(): bool
    {
        return $this->noHours || $this->clash || $this->unavailable;
    }
}
