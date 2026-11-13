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
     * Los nombres de las carreras pedidas, también de las borradas, para las tablas de nombramientos.
     *
     * @param  list<string>  $careerIds
     * @return array<string, string> id de carrera → nombre
     */
    public function careerNames(array $careerIds): array;

    /**
     * Las carreras que se ofrecen activas en el campus, para elegir una al nombrar a un coordinador; sin campus (el
     * coordinador de todos los campus de su modalidad, AZ-S17), las que se ofrecen activas en algún campus activo.
     *
     * @return array<string, string> id de carrera → «ISC · Ingeniería en Sistemas Computacionales», por código
     */
    public function careerOptions(?string $campusId): array;

    /**
     * Las mismas carreras que careerOptions(), agrupadas por el departamento que las coordina y en el orden de su
     * código (las carreras, por código dentro del departamento), para la lista con casillas de «Nombrar».
     *
     * @return list<array{id: string, code: string, name: string, careers: list<array{id: string, code: string, name: string}>}>
     */
    public function careerGroups(?string $campusId): array;
}
