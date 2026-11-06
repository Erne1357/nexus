<?php

namespace App\Modules\CatalogValidation\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo CatalogValidation: el avance de validación y la revisión de catálogos de un departamento. La
 * clave es `catalog_validation.<recurso>.<accion>`. Las acciones sobre una revisión declaran campus (nulo en los
 * catálogos generales) y unidad (el departamento); el avance no declara dimensiones. Corregir, validar, reabrir y
 * observar solo son del jefe del departamento: el admin no los tiene (CV-S1, ver el provider).
 */
enum CatalogValidationPermission: string implements Permission
{
    case ProgressView = 'catalog_validation.progress.view';
    case ProgressRemind = 'catalog_validation.progress.remind';
    case ReviewView = 'catalog_validation.review.view';
    case ReviewResolveFlag = 'catalog_validation.review.resolve_flag';
    case ReviewCorrect = 'catalog_validation.review.correct';
    case ReviewValidate = 'catalog_validation.review.validate';
    case ReviewReopen = 'catalog_validation.review.reopen';
    case ReviewFlag = 'catalog_validation.review.flag';

    public function label(): string
    {
        return match ($this) {
            self::ProgressView => 'Ver el avance de validación de catálogos',
            self::ProgressRemind => 'Recordar a los jefes sus catálogos pendientes',
            self::ReviewView => 'Ver la revisión de catálogos de un departamento',
            self::ReviewResolveFlag => 'Marcar como resuelta una observación',
            self::ReviewCorrect => 'Corregir los registros de una revisión',
            self::ReviewValidate => 'Validar una revisión',
            self::ReviewReopen => 'Reabrir una revisión validada',
            self::ReviewFlag => 'Poner observaciones en una revisión',
        };
    }

    public function dimensions(): array
    {
        return match ($this) {
            self::ProgressView, self::ProgressRemind => [],
            default => [Dimension::Campus, Dimension::Unit],
        };
    }

    public function isPersonal(): bool
    {
        return false;
    }

    /**
     * Lo académico (AZ-02, AZ-S16): a los departamentos académicos y a la Subdirección Académica.
     */
    public function appliesTo(): ?UnitApplicability
    {
        return UnitApplicability::academic();
    }
}
