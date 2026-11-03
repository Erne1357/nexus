<?php

namespace App\Modules\Core\Enums;

/**
 * Puestos de R1 (PROJECT.md, "Actores y roles"). El valor es el de `role_assignments.role` y, mientras exista
 * spatie/permission, el nombre del rol de spatie. R3 añade `alumno` y `servicios_escolares`.
 */
enum Role: string
{
    case Admin = 'admin';
    case Subdireccion = 'subdireccion';
    case JefeDepartamento = 'jefe_departamento';
    case JefeProyectoDocente = 'jefe_proyecto_docente';
    case Coordinador = 'coordinador';
    case Docente = 'docente';

    /**
     * Nombre para la interfaz (la credencial y la página de inicio), como lo nombran PROJECT.md y el
     * glosario.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administración',
            self::Subdireccion => 'Subdirección Académica',
            self::JefeDepartamento => 'Jefe de departamento académico',
            self::JefeProyectoDocente => 'Jefe de proyecto docente',
            self::Coordinador => 'Coordinador de carrera',
            self::Docente => 'Docente',
        };
    }

    /**
     * Columnas de `role_assignments` que un nombramiento de este puesto debe llenar para contar en ScopedContext
     * (columna "Alcance" de PROJECT.md). Admin no necesita alcance y el docente es de todo el Tec. La Subdirección
     * va sobre su unidad (Subdirección Académica, AZ-S3) y el jefe de departamento sobre la de su departamento, los
     * dos en todo el Tec: no piden campus, y sin campus valen en todos los activos. El jefe de proyecto docente y el
     * coordinador sí piden campus. Un nombramiento con un campus que su puesto no pide sigue valiendo solo en ese
     * campus.
     *
     * @return list<'campus_id'|'org_unit_id'|'career_id'>
     */
    public function scopeColumns(): array
    {
        return match ($this) {
            self::Admin, self::Docente => [],
            self::Subdireccion, self::JefeDepartamento => ['org_unit_id'],
            self::JefeProyectoDocente => ['campus_id', 'org_unit_id'],
            self::Coordinador => ['campus_id', 'career_id'],
        };
    }

    /**
     * Si el puesto es uno solo por unidad en cada fecha (AZ-S7): lo garantizan las exclusiones
     * `role_assignments_one_jefe` y `role_assignments_one_subdireccion`.
     */
    public function isExclusive(): bool
    {
        return match ($this) {
            self::JefeDepartamento, self::Subdireccion => true,
            default => false,
        };
    }
}
