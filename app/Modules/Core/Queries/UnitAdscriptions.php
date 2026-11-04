<?php

namespace App\Modules\Core\Queries;

/**
 * Quiénes están adscritos a cada unidad del organigrama, para contar las personas de cada caja del Organigrama
 * (OrgChartQuery). Core no conoce a los docentes: liga NoUnitAdscriptions, y Faculty, que sí los tiene, lo reemplaza en
 * su register() con sus adscripciones (el id de un departamento es el de su unidad).
 */
interface UnitAdscriptions
{
    /**
     * @return array<string, list<string>> id de unidad → ids de usuario
     */
    public function userIdsByUnit(): array;
}
