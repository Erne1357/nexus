<?php

namespace App\Modules\Core\Enums;

/**
 * Tipo de unidad del organigrama (`org_units.kind`, ADR-0018). El valor lo fija un CHECK en la base.
 */
enum OrgUnitKind: string
{
    case Direccion = 'direccion';
    case Subdireccion = 'subdireccion';
    case Division = 'division';
    case DepartamentoAcademico = 'departamento_academico';
    case Departamento = 'departamento';
    case Oficina = 'oficina';

    /**
     * Nombre para la interfaz.
     */
    public function label(): string
    {
        return match ($this) {
            self::Direccion => 'Dirección',
            self::Subdireccion => 'Subdirección',
            self::Division => 'División',
            self::DepartamentoAcademico => 'Departamento académico',
            self::Departamento => 'Departamento',
            self::Oficina => 'Oficina',
        };
    }
}
