<?php

namespace App\Modules\Scheduling\Rules\Evaluator;

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Rules\Constraints\Constraint;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * El evaluador en memoria de las reglas duras (tarea 6.5): corre las mismas clases de HC-01 a HC-13 que el validador
 * (ScheduleValidator::CONSTRAINTS) sobre una foto que ya se tiene, sin leer la base ni pedir nada al contenedor, para
 * que la heurística del motor y la pantalla de corridas comparen soluciones candidatas con una sola definición de cada
 * regla. La foto puede venir de la base (ScheduleStateQuery) o del contrato del motor (Solver).
 *
 * Es barato de llamar muchas veces: crea las reglas una vez y cada llamada solo recorre lo que toca al alcance. Para
 * preguntar «¿y si…?» se le da la foto de withSession() o withTeacher() y el alcance del grupo (forGroup()). No guarda
 * nada entre llamadas, así que no es singleton ni lo necesita (Octane).
 */
final class HardRuleEvaluator
{
    /** @var list<Constraint> */
    private readonly array $constraints;

    public function __construct()
    {
        $this->constraints = array_values(array_filter(
            array_map(fn (string $constraint): Constraint => new $constraint, ScheduleValidator::CONSTRAINTS),
            fn (Constraint $constraint): bool => str_starts_with($constraint->rule(), 'HC-'),
        ));
    }

    /**
     * Los ids de las reglas que corre, en orden: HC-01 a HC-13.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return array_map(fn (Constraint $constraint): string => $constraint->rule(), $this->constraints);
    }

    /**
     * Los choques duros de lo que toca al alcance; de todas las reglas, o solo de los ids indicados.
     *
     * @param  list<string>|null  $rules
     */
    public function evaluate(ScheduleState $state, ValidationScope $scope, ?array $rules = null): ValidationResult
    {
        return new ValidationResult((function () use ($state, $scope, $rules): iterable {
            foreach ($this->constraints as $constraint) {
                if ($rules !== null && ! in_array($constraint->rule(), $rules, true)) {
                    continue;
                }

                foreach ($constraint->check($state, $scope) as $violation) {
                    if ($violation->severity === ConflictSeverity::Hard) {
                        yield $violation;
                    }
                }
            }
        })());
    }

    /**
     * Los choques duros del grupo (los que lo tienen como grupo afectado; el otro grupo de un empalme tiene los suyos).
     *
     * @param  list<string>|null  $rules
     */
    public function forGroup(ScheduleState $state, string $groupId, ?array $rules = null): ValidationResult
    {
        return new ValidationResult(array_filter(
            $this->evaluate($state, ValidationScope::groups($groupId), $rules)->violations(),
            fn (Violation $violation): bool => $violation->groupId === $groupId,
        ));
    }
}
