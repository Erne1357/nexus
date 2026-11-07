<?php

namespace App\Modules\Staffing\Rules;

/**
 * Un candidato ya ordenado: posición, puntaje y desglose que se muestra completo (reglas-de-negocio.md, «Ranking
 * docente (desempate)»).
 */
final readonly class RankedCandidate
{
    public function __construct(
        public int $rank,
        public float $score,
        public RankingCandidate $candidate,
    ) {}

    /**
     * @return array{base_hours: int, history_count: int, qualification: ?string, evaluation: ?float, remaining_hours: ?int, flags: list<string>}
     */
    public function breakdown(): array
    {
        $candidate = $this->candidate;
        $flags = array_keys(array_filter([
            'sin_horas' => $candidate->noHours,
            'choque' => $candidate->clash,
            'no_disponible' => $candidate->unavailable,
            'sin_evaluacion' => $candidate->evaluation === null,
        ]));

        return [
            'base_hours' => $candidate->baseHours,
            'history_count' => $candidate->historyCount,
            'qualification' => $candidate->qualification?->value,
            'evaluation' => $candidate->evaluation,
            'remaining_hours' => $candidate->remainingHours,
            'flags' => $flags,
        ];
    }
}
