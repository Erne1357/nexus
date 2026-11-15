<?php

namespace App\Modules\Core\Queries;

/**
 * Quiénes están adscritos a cada unidad del organigrama, para contar las personas de cada caja del Organigrama
 * (OrgChartQuery), y a qué unidades está adscrita una persona (DeactivationPreviewQuery). Core no conoce a los docentes: liga NoUnitAdscriptions, y Faculty, que sí los tiene, lo reemplaza en
 * su register() con sus adscripciones (el id de un departamento es el de su unidad).
 */
interface UnitAdscriptions
{
    /**
     * @return array<string, list<string>> id de unidad → ids de usuario
     */
    public function userIdsByUnit(): array;

    /**
     * Las unidades a las que está adscrito un usuario, para la vista previa de la baja de su cuenta (CF-09).
     *
     * @return list<string> ids de unidad
     */
    public function unitIdsOf(string $userId): array;
}
