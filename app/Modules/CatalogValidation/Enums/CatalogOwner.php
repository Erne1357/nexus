<?php

namespace App\Modules\CatalogValidation\Enums;

/**
 * Quién valida un catálogo cada periodo (CV15, confirmada el 20 de noviembre de 2026): docentes y salones, el jefe de
 * cada departamento académico, una revisión por departamento; materias, planes de estudio y carreras, el jefe del
 * Departamento de Servicios Escolares, una sola revisión para todo el Tec con todos sus registros
 * (`catalog_reviews.department_id` nulo, CHECK `catalog_reviews_owner_check`).
 */
enum CatalogOwner: string
{
    case Department = 'department';
    case SchoolServices = 'school_services';
}
