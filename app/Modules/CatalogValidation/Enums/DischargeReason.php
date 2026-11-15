<?php

namespace App\Modules\CatalogValidation\Enums;

/**
 * El motivo opcional con que el jefe quita a un docente de su departamento desde la revisión de docentes (CV8, D8,
 * confirmado el 14 de noviembre de 2026). Queda en la bitácora de DischargeReviewTeacher, en `properties.reason`, como
 * `{kind, text?}`; el texto solo va con «Otro», que lo pide.
 */
enum DischargeReason: string
{
    case Retirement = 'retirement';
    case Resignation = 'resignation';
    case DepartmentChange = 'department_change';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Retirement => 'Jubilación',
            self::Resignation => 'Renuncia',
            self::DepartmentChange => 'Cambio de departamento',
            self::Other => 'Otro',
        };
    }
}
