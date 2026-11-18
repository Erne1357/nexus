<?php

namespace App\Modules\Solver\Evaluation;

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Rules\Evaluator\HardRuleEvaluator;
use App\Modules\Scheduling\Rules\Soft\SoftScorer;
use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Solver\Contract\HardViolation;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\SoftScore;
use App\Modules\Solver\Contract\SolverInput;

/**
 * Evalúa una solución candidata del motor con las reglas de Scheduling, en memoria (tarea 6.5): las duras activas de
 * la entrada (`hard_rules`) con HardRuleEvaluator y el puntaje blando con los pesos de la entrada (`soft_weights`) con
 * SoftScorer, sobre la foto de ContractState. Lo usan la heurística en PHP, la pantalla de corridas y el benchmark.
 *
 * Cada choque sale una vez como HardViolation: los de dos grupos (empalmes de docente, salón o alumno tipo) llevan los
 * dos grupos de la entrada que chocan, y nunca un grupo de contexto. El puntaje cuenta lo que toca a los grupos
 * movibles. Sin estado entre llamadas.
 */
final class ContractEvaluator
{
    private readonly HardRuleEvaluator $hard;

    private readonly SoftScorer $soft;

    public function __construct()
    {
        $this->hard = new HardRuleEvaluator;
        $this->soft = new SoftScorer;
    }

    /**
     * @param  list<ProposedSession>  $sessions  las sesiones propuestas de los grupos movibles
     */
    public function evaluate(SolverInput $input, array $sessions): ContractEvaluation
    {
        return $this->evaluateState(ContractState::from($input, $sessions));
    }

    /**
     * El veredicto sobre una foto ya armada (la de withState() tras un «¿y si…?»), de los grupos movibles o del alcance
     * indicado.
     */
    public function evaluateState(ContractState $state, ?ValidationScope $scope = null): ContractEvaluation
    {
        return new ContractEvaluation($this->hardViolations($state, $scope), $this->softScore($state, $scope), $state);
    }

    /**
     * @return list<HardViolation>
     */
    public function hardViolations(ContractState $state, ?ValidationScope $scope = null): array
    {
        return self::toContract($state, $this->hard->evaluate($state->state, $scope ?? $state->scope, $state->hardRules));
    }

    public function softScore(ContractState $state, ?ValidationScope $scope = null): SoftScore
    {
        $result = $this->soft->score($state->state, $scope ?? $state->scope, $state->context, $state->softWeights);

        return new SoftScore($result->total, $result->byRule);
    }

    /**
     * @return list<HardViolation>
     */
    private static function toContract(ContractState $state, ValidationResult $result): array
    {
        $violations = [];

        foreach ($result->violations() as $violation) {
            if ($violation->severity !== ConflictSeverity::Hard || $violation->groupId === null || ! $state->isInputGroup($violation->groupId)) {
                continue;
            }

            $groupIds = [$violation->groupId];
            $other = $violation->related['other_group_id'] ?? null;

            if (is_string($other) && $other !== $violation->groupId && $state->isInputGroup($other)) {
                $groupIds[] = $other;
            }

            $sorted = $groupIds;
            sort($sorted);
            $sessions = array_filter([$violation->related['session_id'] ?? null, $violation->related['other_session_id'] ?? null], 'is_string');
            sort($sessions);
            $key = implode('|', [$violation->rule, ...$sorted, ...$sessions, count($groupIds) === 1 ? $violation->message : '']);

            $violations[$key] ??= new HardViolation(
                $violation->rule,
                $groupIds,
                $violation->message,
                $violation->subjectType === 'teacher' ? $violation->subjectId : ($violation->related['teacher_id'] ?? null),
                $violation->subjectType === 'room' ? $violation->subjectId : ($violation->related['room_id'] ?? null),
            );
        }

        return array_values($violations);
    }
}
