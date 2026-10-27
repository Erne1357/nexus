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
     * Columnas de `role_scopes` que un alcance de este rol debe llenar para contar en
     * ScopedContext (columna "Alcance" de PROJECT.md). Admin no necesita alcance; al docente,
     * cuyo alcance es su propia carga, se le pide el campus donde la tiene.
     *
     * @return list<'campus_id'|'department_id'|'career_id'>
     */
    public function scopeColumns(): array
    {
        return match ($this) {
            self::Admin => [],
            self::Subdireccion, self::Docente => ['campus_id'],
            self::JefeDepartamento, self::JefeProyectoDocente => ['campus_id', 'department_id'],
            self::Coordinador => ['campus_id', 'career_id'],
        };
    }
}
