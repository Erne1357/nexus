<?php

namespace App\Modules\Students\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Students: consulta de alumnos. La clave es `students.<recurso>.<accion>`.
 */
enum StudentsPermission: string implements Permission
{
    case StudentView = 'students.student.view';

    public function label(): string
    {
        return match ($this) {
            self::StudentView => 'Ver alumnos',
        };
    }

    public function dimensions(): array
    {
        return [Dimension::Campus, Dimension::Unit, Dimension::Career];
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
