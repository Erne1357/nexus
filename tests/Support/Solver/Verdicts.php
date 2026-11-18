<?php

namespace Tests\Support\Solver;

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use App\Modules\Solver\Evaluation\ContractState;

/**
 * El veredicto de las pruebas de equivalencia de la tarea 6.5: las reglas duras violadas por cada grupo movible de una
 * entrada del motor, con el validador de la fase 3 sobre la foto de la base y con el evaluador en memoria sobre el
 * contrato (con las sesiones actuales de los movibles). Cada lista va ordenada, y los grupos por id.
 *
 * El contrato solo trae las cohortes de HC-03 (el único grupo de una materia en su semestre), así que el choque del
 * núcleo sin combinación, que sale de materias con varios grupos y no lleva `other_group_id`, no se puede ver en
 * memoria: `$withTypicalStudentCore` en falso lo deja fuera del lado de la base.
 */
final class Verdicts
{
    /**
     * @return array{array<string, list<string>>, array<string, list<string>>} [base, memoria]
     */
    public static function of(SolverInput $input, ScheduleState $databaseState, bool $withTypicalStudentCore = true): array
    {
        $movable = array_values(array_map(fn (GroupInput $group): string => $group->id, array_filter($input->groups, fn (GroupInput $group): bool => $group->movable)));
        $fromDatabase = [];

        foreach (app(ScheduleValidator::class)->validate($databaseState, ValidationScope::groups(...$movable))->violations() as $violation) {
            $isCore = $violation->rule === 'HC-03' && ! isset($violation->related['other_group_id']);

            if ($violation->severity === ConflictSeverity::Hard && in_array($violation->groupId, $movable, true) && ($withTypicalStudentCore || ! $isCore)) {
                $fromDatabase[$violation->groupId][$violation->rule] = true;
            }
        }

        $fromDatabase = array_map(function (array $rules): array {
            $rules = array_keys($rules);
            sort($rules);

            return $rules;
        }, $fromDatabase);

        $inMemory = array_intersect_key(
            (new ContractEvaluator)->evaluate($input, ContractState::currentSessions($input))->rulesByGroup(),
            array_flip($movable),
        );

        ksort($fromDatabase);
        ksort($inMemory);

        return [$fromDatabase, $inMemory];
    }
}
