<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

use App\Modules\Solver\Contract\HardViolation;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Evaluation\ContractEvaluation;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use Closure;

/**
 * La validación final de la heurística (tarea 6.6): el evaluador en memoria es la autoridad, no los índices del
 * constructor. Si le quedan violaciones duras en grupos movibles con sesiones, quita las sesiones de los menos grupos
 * que las resuelven todas: primero el que aparece en más violaciones y, entre los que empatan, el que se colocó después.
 * Cada uno queda sin programar con la regla y el mensaje del evaluador como motivo, y se vuelve a validar hasta que no
 * quede ninguna. Así nunca sale un choque duro en los movibles. Quitar sesiones no crea choques, así que pasado el
 * plazo quita de una vez todos los grupos de las violaciones que queden y termina.
 *
 * HC-08 no depende del horario (cuenta los grupos del docente, tengan o no sesiones): quitar sesiones no lo corrige, así
 * que se deja en la salida como problema de la entrada (PS-08, supuesto por confirmar). Sin estado entre llamadas.
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
     * @param  (Closure(): float)|null  $clock  con `deadline`, el reloj del plazo
     * @return array{array<string, list<ProposedSession>>, array<string, list<string>>, ContractEvaluation, int}
     */
    public function clean(SolverInput $input, array $sessions, array $placementOrder, ?Closure $clock = null, float $deadline = INF): array
    {
        $removed = [];
        $evaluations = 0;

        do {
            $evaluation = $this->evaluator->evaluate($input, array_merge([], ...array_values($sessions)));
            $evaluations++;
            $late = $clock !== null && $clock() >= $deadline;
            $round = $this->victims($evaluation->hardViolations, $sessions, $placementOrder, $late);

            foreach ($round as $groupId => $reasons) {
                unset($sessions[$groupId]);
                $removed[$groupId] = $reasons;
            }
        } while ($round !== []);

        return [$sessions, $removed, $evaluation, $evaluations];
    }

    /**
     * Los grupos a los que se quitan las sesiones, con sus motivos: un cubrimiento voraz de las violaciones (el grupo en
     * más violaciones primero; empate, el colocado después) o, con `$all`, todos los grupos colocados que aparecen.
     *
     * @param  list<HardViolation>  $violations
     * @param  array<string, list<ProposedSession>>  $sessions
     * @param  array<string, int>  $placementOrder
     * @return array<string, list<string>>
     */
    private function victims(array $violations, array $sessions, array $placementOrder, bool $all): array
    {
        $open = [];

        foreach ($violations as $violation) {
            $placed = array_values(array_filter($violation->groupIds, fn (string $groupId): bool => isset($sessions[$groupId])));

            if (! in_array($violation->rule, self::SESSIONLESS_RULES, true) && $placed !== []) {
                $open[] = [$placed, "{$violation->rule}: {$violation->message}"];
            }
        }

        $victims = [];

        while ($open !== []) {
            $count = [];

            foreach ($open as [$placed]) {
                foreach ($placed as $groupId) {
                    $count[$groupId] = ($count[$groupId] ?? 0) + 1;
                }
            }

            uksort($count, fn (string $a, string $b): int => [$count[$b], $placementOrder[$b] ?? PHP_INT_MAX] <=> [$count[$a], $placementOrder[$a] ?? PHP_INT_MAX]);
            $chosen = $all ? array_map('strval', array_keys($count)) : [(string) array_key_first($count)];

            foreach ($open as $index => [$placed, $reason]) {
                foreach (array_intersect($placed, $chosen) as $groupId) {
                    $victims[$groupId][] = $reason;
                }

                if (array_intersect($placed, $chosen) !== []) {
                    unset($open[$index]);
                }
            }
        }

        return array_map(fn (array $reasons): array => array_values(array_unique($reasons)), $victims);
    }
}
