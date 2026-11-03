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

    /**
     * Las carreras que se ofrecen activas en el campus, para elegir una al nombrar a un coordinador.
     *
     * @return array<string, string> id de carrera → «ISC · Ingeniería en Sistemas Computacionales», por código
     */
    public function careerOptions(string $campusId): array;
}
