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
    /**
     * Las reglas, por id y en orden: el orden del desglose.
     *
     * @var array<string, class-string<SoftRule>>
     */
    public const array RULES = [
        'SC-01' => StudentGaps::class,
        'SC-02' => GroupDaySpread::class,
        'SC-03' => DefaultRoom::class,
        'SC-04' => PreferredBlocks::class,
        'SC-05' => TeacherGaps::class,
        'SC-06' => BuildingChanges::class,
        'SC-07' => ConsecutiveSemesters::class,
        'SC-08' => HonorariosAvailability::class,
        'SC-09' => CampusTravelTime::class,
    ];

    /** @var array<string, SoftRule> */
    private readonly array $rules;

    public function __construct()
    {
        $this->rules = array_map(fn (string $rule): SoftRule => new $rule, self::RULES);
    }

    /**
     * @param  array<string, int>  $weights  peso por id de regla; las demás llaves se ignoran
     */
    public function score(ScheduleState $state, ValidationScope $scope, SoftContext $context, array $weights): SoftResult
    {
        $units = array_fill_keys(array_keys(self::RULES), 0);
        $byRule = $units;

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
