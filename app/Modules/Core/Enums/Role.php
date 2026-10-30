<?php

namespace App\Modules\Core\Enums;

/**
 * Roles de R1 (PROJECT.md, "Actores y roles"). El valor es el nombre del rol en
 * spatie/permission y en `role_scopes.role`. R3 añade `alumno` y `servicios_escolares`.
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
     * Columnas de `role_scopes` que un alcance de este rol debe llenar para contar en
     * ScopedContext (columna "Alcance" de PROJECT.md). Admin no necesita alcance. La subdirección
     * y el docente son de todo el Tec, y el jefe de departamento lo es de un departamento en todo
     * el Tec: su alcance no pide campus, y sin campus vale en todos los activos. El jefe de
     * proyecto docente y el coordinador sí piden campus. Un alcance con un campus que su rol no
     * pide sigue valiendo solo en ese campus.
     *
     * @return list<'campus_id'|'department_id'|'career_id'>
     */
    public function scopeColumns(): array
    {
        return match ($this) {
            self::Admin, self::Subdireccion, self::Docente => [],
            self::JefeDepartamento => ['department_id'],
            self::JefeProyectoDocente => ['campus_id', 'department_id'],
            self::Coordinador => ['campus_id', 'career_id'],
        };
    }
}
