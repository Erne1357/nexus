<?php

namespace App\Modules\Core\Authorization;

/**
 * Los nombres cortos que Core no conoce y otro módulo sí, para el resumen de cada puesto en la credencial
 * (RoleSummaryQuery). Core liga DefaultScopeLabels; el módulo dueño de los datos lo reemplaza.
 */
interface ScopeLabels
{
    /**
     * @param  list<string>  $careerIds
     * @return array<string, string> id de carrera → código
     */
    public function careers(array $careerIds): array;
}
