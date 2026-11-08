<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Staffing\Rules\RankingCandidate;

/**
 * Los datos de Faculty de un docente para el ranking de un grupo (reglas-de-negocio.md, «Ranking docente
 * (desempate)»). Las marcas «sin horas», «choque» y «no disponible» las decide Scheduling/Staffing al armar el
 * candidato con toCandidate().
 */
final readonly class RankingInput
{
    public function __construct(
        public string $teacherId,
        public string $name,
        public int $baseHours,
        public int $historyCount,
        public ?QualificationLevel $qualification,
        public ?float $evaluation,
        public ?int $previousRank,
        public bool $fromOtherDepartment,
    ) {}

    public function toCandidate(bool $noHours, bool $clash, bool $unavailable, ?int $remainingHours): RankingCandidate
    {
        return new RankingCandidate(
            $this->teacherId,
            $this->name,
            $this->baseHours,
            $this->historyCount,
            $this->qualification,
            $this->evaluation,
            $remainingHours,
            $noHours,
            $clash,
            $unavailable,
            $this->previousRank,
        );
    }
}
