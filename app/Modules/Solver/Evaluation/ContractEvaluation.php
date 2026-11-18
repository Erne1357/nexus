<?php

namespace App\Modules\Solver\Evaluation;

use App\Modules\Solver\Contract\HardViolation;
use App\Modules\Solver\Contract\SoftScore;

/**
 * El veredicto de una solución candidata sobre la entrada del motor (tarea 6.5): las violaciones duras con la forma de
 * `hard_violations` de la salida, el puntaje blando con la forma de `soft_score` y la foto que se evaluó, para seguir
 * preguntando «¿y si…?» sobre ella.
 */
final readonly class ContractEvaluation
{
    /**
     * @param  list<HardViolation>  $hardViolations
     */
    public function __construct(
        public array $hardViolations,
        public SoftScore $softScore,
        public ContractState $state,
    ) {}

    /**
     * Los ids de las reglas duras violadas por cada grupo de la entrada que aparece en alguna violación.
     *
     * @return array<string, list<string>>
     */
    public function rulesByGroup(): array
    {
        $rules = [];

        foreach ($this->hardViolations as $violation) {
            foreach ($violation->groupIds as $groupId) {
                $rules[$groupId][$violation->rule] = true;
            }
        }

        return array_map(function (array $ids): array {
            $ids = array_keys($ids);
            sort($ids);

            return $ids;
        }, $rules);
    }
}
