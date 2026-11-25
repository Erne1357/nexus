<?php

namespace App\Modules\Students\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\ReachesInactiveModalities;
use App\Modules\Core\Authorization\ReadsByAction;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Students: consulta de alumnos y, del alumno sobre lo suyo, «Mis datos» (AC-15). La clave es
 * `students.<recurso>.<accion>`.
 */
enum StudentsPermission: string implements Permission, ReachesInactiveModalities
{
    use ReadsByAction;

    case StudentView = 'students.student.view';
    case MyRecordView = 'students.my_record.view';
    case PhotoManage = 'students.photo.manage';

    public function label(): string
    {
        return match ($this) {
            self::StudentView => 'Ver alumnos',
            self::MyRecordView => 'Ver mis datos (alumno)',
            self::PhotoManage => 'Subir y cambiar las fotos de los alumnos',
        };
    }

    /**
     * Los datos de un alumno son de su campus y de su modalidad (AZ-S17, MS7): quien ve alumnos con el nombramiento
     * limitado a un campus solo ve los de ese campus (supuesto por confirmar).
     */
    public function dimensions(): array
    {
        return match ($this) {
            self::StudentView => [Dimension::Modality, Dimension::Campus, Dimension::Unit, Dimension::Career],
            self::PhotoManage => [Dimension::Unit],
            self::MyRecordView => [],
        };
    }

    /**
     * Una modalidad inactiva solo bloquea trabajar su carga, no ver a sus alumnos (M10): quien no fija modalidad sigue
     * viendo a los de las inactivas.
     */
    public function reachesInactiveModalities(): bool
    {
        return $this === self::StudentView;
    }

    public function isPersonal(): bool
    {
        return $this === self::MyRecordView;
    }

    /**
     * Lo académico (AZ-02, AZ-S16): a los departamentos académicos y a la Subdirección Académica. «Mis datos» es
     * personal y el alumno no tiene unidad: a cualquiera. Las fotos de los alumnos son de Comunicación y Difusión (CO-01,
     * CO-06).
     */
    public function appliesTo(): ?UnitApplicability
    {
        return match ($this) {
            self::PhotoManage => UnitApplicability::of(codes: [OrgTree::COMMUNICATION]),
            self::MyRecordView => null,
            default => UnitApplicability::academic(),
        };
    }
}
