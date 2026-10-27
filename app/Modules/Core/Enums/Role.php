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
}
