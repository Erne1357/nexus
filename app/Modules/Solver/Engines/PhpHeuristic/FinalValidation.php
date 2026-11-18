<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

use App\Modules\Solver\Contract\HardViolation;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Evaluation\ContractEvaluation;
use App\Modules\Solver\Evaluation\ContractEvaluator;

/**
 * La validación final de la heurística (tarea 6.6): el evaluador en memoria es la autoridad, no los índices del
 * constructor. Si le queda una violación dura en un grupo movible con sesiones, le quita las sesiones (de un choque entre
 * dos grupos colocados, al que se colocó después), lo deja sin programar con la regla y el mensaje del evaluador como
 * motivo, y vuelve a validar hasta que no quede ninguna. Así nunca sale un choque duro en los movibles.
 *
 * HC-08 no depende del horario (cuenta los grupos del docente, tengan o no sesiones): quitar sesiones no lo corrige, así
 * que se deja en la salida como problema de la entrada (supuesto por confirmar). Sin estado entre llamadas.
 */
final readonly class FinalValidation
{
    /** Las reglas duras que no dependen de las sesiones. */
    public const array SESSIONLESS_RULES = ['HC-08'];

    public function __construct(private ContractEvaluator $evaluator = new ContractEvaluator) {}

    /**
     * Las sesiones que quedan, los grupos quitados con su motivo, la última evaluación y cuántas evaluaciones hubo.
     *
     * @param  array<string, list<ProposedSession>>  $sessions  por grupo colocado
     * @param  array<string, int>  $placementOrder  en qué turno se colocó cada grupo
     * @return array{array<string, list<ProposedSession>>, array<string, list<string>>, ContractEvaluation, int}
     */
    public function clean(SolverInput $input, array $sessions, array $placementOrder): array
    {
        $removed = [];
        $evaluations = 0;

        do {
            $evaluation = $this->evaluator->evaluate($input, array_merge([], ...array_values($sessions)));
            $evaluations++;
            $round = $this->victims($evaluation->hardViolations, $sessions, $placementOrder);

            foreach ($round as $groupId => $reasons) {
                unset($sessions[$groupId]);
                $removed[$groupId] = $reasons;
            }
        } while ($round !== []);

        return [$sessions, $removed, $evaluation, $evaluations];
    }

    /**
     * @param  list<HardViolation>  $violations
     * @param  array<string, list<ProposedSession>>  $sessions
     * @param  array<string, int>  $placementOrder
     * @return array<string, list<string>>
     */
    private function victims(array $violations, array $sessions, array $placementOrder): array
    {
        $victims = [];

        foreach ($violations as $violation) {
            if (in_array($violation->rule, self::SESSIONLESS_RULES, true)) {
                continue;
            }

            $placed = array_values(array_filter($violation->groupIds, fn (string $groupId): bool => isset($sessions[$groupId])));

            if ($placed === []) {
                continue;
            }

            usort($placed, fn (string $a, string $b): int => ($placementOrder[$b] ?? PHP_INT_MAX) <=> ($placementOrder[$a] ?? PHP_INT_MAX));
            $victims[$placed[0]][] = "{$violation->rule}: {$violation->message}";
        }

        return array_map(fn (array $reasons): array => array_values(array_unique($reasons)), $victims);
    }
}
