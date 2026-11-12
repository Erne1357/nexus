<?php

namespace App\Modules\Students\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Students: consulta de alumnos y, del alumno sobre lo suyo, «Mis datos» (AC-15). La clave es
 * `students.<recurso>.<accion>`.
 */
enum StudentsPermission: string implements Permission
{
    case StudentView = 'students.student.view';
    case MyRecordView = 'students.my_record.view';

    public function label(): string
    {
        return match ($this) {
            self::StudentView => 'Ver alumnos',
            self::MyRecordView => 'Ver mis datos (alumno)',
        };
    }

    /**
     * Los datos de un alumno son de su campus y de su modalidad (AZ-S17, MS7): quien ve alumnos con el nombramiento
     * limitado a un campus solo ve los de ese campus (supuesto por confirmar).
     */
    public function dimensions(): array
    {
        return $this->isPersonal() ? [] : [Dimension::Modality, Dimension::Campus, Dimension::Unit, Dimension::Career];
    }

    public function isPersonal(): bool
    {
        return $this === self::MyRecordView;
    }

    /**
     * Lo académico (AZ-02, AZ-S16): a los departamentos académicos y a la Subdirección Académica. «Mis datos» es
     * personal y el alumno no tiene unidad: a cualquiera.
     */
    public function appliesTo(): ?UnitApplicability
    {
        return $this->isPersonal() ? null : UnitApplicability::academic();
    }
}
