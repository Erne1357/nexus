<?php

namespace App\Modules\Academic\Enums;

use App\Modules\Core\Enums\Role;

/**
 * Qué cargo tiene alguien en un departamento (`department_heads.kind`). El jefe es uno vigente por departamento para
 * todo el Tec, sin campus (confirmado el 30 de octubre de 2026). De jefe de proyecto docente puede haber varios
 * (confirmado el 29 de octubre de 2026), cada uno en un campus (supuesto por confirmar).
 */
enum DepartmentHeadKind: string
{
    case Jefe = 'jefe';
    case JefeProyectoDocente = 'jefe_proyecto_docente';

    public function label(): string
    {
        return match ($this) {
            self::Jefe => 'Jefe de departamento',
            self::JefeProyectoDocente => 'Jefe de proyecto docente',
        };
    }

    /**
     * El rol que da: la tarea 1.14 asigna el rol y su alcance desde `department_heads`.
     */
    public function role(): Role
    {
        return match ($this) {
            self::Jefe => Role::JefeDepartamento,
            self::JefeProyectoDocente => Role::JefeProyectoDocente,
        };
    }
}
