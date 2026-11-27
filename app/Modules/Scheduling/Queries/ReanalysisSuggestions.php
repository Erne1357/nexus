<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Demand\Enums\DemandBalance;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorGroup;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;

/**
 * Lo que pinta la parte de la tarea 8.3a del drawer de sugerencias de una materia en un campus: las sugerencias del
 * asesor (ReanalysisAdvisor), los grupos vivos de ahora (`groups`, con sus modelos en `models` para que la pantalla
 * pregunte a GroupPolicy qué puede hacer el usuario con cada uno), los lugares de ahora de cada carrera (`seats`, por id;
 * los de la versión publicada están en el bloque), la clave de cada carrera con cupo (`careerCodes`, por id), el umbral
 * de RA-04 con el que se clasificó (`threshold`, en %) y la clase de cada carrera clasificada con sus lugares de ahora
 * (`balances`, por id; sin las de primer semestre, RA-09): la misma con que sugiere el asesor, para que el drawer diga
 * cómo quedó una carrera que ya se ajustó (ensayo 8.11, F-08).
 */
final readonly class ReanalysisSuggestions
{
    /**
     * @param  list<ReanalysisSuggestion>  $suggestions
     * @param  list<AdvisorGroup>  $groups
     * @param  array<string, Group>  $models
     * @param  array<string, int>  $seats
     * @param  array<string, string>  $careerCodes
     * @param  array<string, DemandBalance>  $balances
     */
    public function __construct(
        public array $suggestions,
        public array $groups,
        public array $models,
        public array $seats,
        public array $careerCodes,
        public int $threshold,
        public array $balances = [],
    ) {}

    public function find(string $key): ?ReanalysisSuggestion
    {
        return array_find($this->suggestions, fn (ReanalysisSuggestion $suggestion): bool => $suggestion->key === $key);
    }
}
