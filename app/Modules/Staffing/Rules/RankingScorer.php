<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Faculty\Enums\QualificationLevel;

/**
 * Ordena a los candidatos de un grupo (reglas-de-negocio.md, «Ranking docente (desempate)»). Puntaje = grupos
 * impartidos × peso + peso del perfil (preferente o apto, no ambos) + evaluación × peso; sin evaluación son 0
 * puntos. Orden: los que pasan su banda de horas (HC-08) después de los demás y los marcados (choque, no disponible)
 * después de todos; dentro de cada bloque, horas base de mayor a menor;
 * puntaje de mayor a menor; posición del periodo anterior (los que no tienen, al final); nombre sin importar
 * mayúsculas ni acentos; id del docente. Determinista. Puro.
 */
final readonly class RankingScorer
{
    public function __construct(private RankingWeights $weights) {}

    /**
     * @param  list<RankingCandidate>  $candidates
     * @return list<RankedCandidate>
     */
    public function rank(array $candidates): array
    {
        $scored = array_map(fn (RankingCandidate $candidate): array => [
            'score' => round($this->score($candidate), 2),
            'candidate' => $candidate,
            'name' => self::fold($candidate->name),
        ], $candidates);

        usort($scored, fn (array $a, array $b): int => [
            $a['candidate']->tier(), -$a['candidate']->baseHours, -$a['score'], $a['candidate']->previousRank ?? PHP_INT_MAX, $a['name'], $a['candidate']->teacherId,
        ] <=> [
            $b['candidate']->tier(), -$b['candidate']->baseHours, -$b['score'], $b['candidate']->previousRank ?? PHP_INT_MAX, $b['name'], $b['candidate']->teacherId,
        ]);

        return array_map(
            fn (array $row, int $index): RankedCandidate => new RankedCandidate($index + 1, $row['score'], $row['candidate']),
            $scored,
            array_keys($scored),
        );
    }

    private function score(RankingCandidate $candidate): float
    {
        $profile = match ($candidate->qualification) {
            QualificationLevel::Preferente => $this->weights->preferente,
            QualificationLevel::Apto => $this->weights->apto,
            null => 0.0,
        };

        return $candidate->historyCount * $this->weights->perGroupTaught
            + $profile
            + ($candidate->evaluation ?? 0.0) * $this->weights->evaluation;
    }

    private static function fold(string $name): string
    {
        $ascii = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()', $name);

        return $ascii === false ? mb_strtolower($name) : $ascii;
    }
}
