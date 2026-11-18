<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * El puntaje blando del motor de horarios (tarea 6.5): suma las reglas SC-01 a SC-09 sobre una foto en memoria, cada
 * una por su peso (`soft_weights` del contrato, o `solver.weights`). Una regla con peso cero, o sin peso, no se calcula.
 * Lo usan la heurística en PHP para comparar soluciones candidatas y la pantalla de corridas, a través del adaptador
 * del contrato (Solver). Puro y sin estado entre llamadas.
 */
final class SoftScorer
{
    /** @var list<string> */
    public const array RULES = ['SC-01', 'SC-02', 'SC-03', 'SC-04', 'SC-05', 'SC-06', 'SC-07', 'SC-08', 'SC-09'];

    /** @var array<string, SoftRule> */
    private readonly array $rules;

    public function __construct()
    {
        $rules = [];

        foreach ([new StudentGaps, new GroupDaySpread, new DefaultRoom, new PreferredBlocks, new TeacherGaps,
            new BuildingChanges, new ConsecutiveSemesters, new HonorariosAvailability, new CampusTravelTime] as $rule) {
            $rules[$rule->rule()] = $rule;
        }

        $this->rules = $rules;
    }

    /**
     * @param  array<string, int>  $weights  peso por id de regla; las demás llaves se ignoran
     */
    public function score(ScheduleState $state, ValidationScope $scope, SoftContext $context, array $weights): SoftResult
    {
        $units = array_fill_keys(self::RULES, 0);
        $byRule = array_fill_keys(self::RULES, 0);

        foreach ($this->rules as $id => $rule) {
            $weight = $weights[$id] ?? 0;

            if ($weight === 0) {
                continue;
            }

            $units[$id] = $rule->penalty($state, $scope, $context);
            $byRule[$id] = $weight * $units[$id];
        }

        return new SoftResult(array_sum($byRule), $byRule, $units);
    }
}
