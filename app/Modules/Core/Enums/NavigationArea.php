<?php

namespace App\Modules\Core\Enums;

/**
 * Área del menú a la que pertenece una entrada (ADR-0015, DESIGN.md "Navigation", MN-01). El orden de
 * los casos es el orden del menú; solo se muestran las áreas con al menos una entrada visible para
 * el usuario. Dentro de un área, las entradas pueden ir en grupos (NavigationGroup).
 *
 * Carga académica reúne todo lo de la carga, del semestre y del docente (tarea 1.24.6): el admin la puede ocultar
 * entera a los demás (MN-05, AcademicLoadVisibility).
 */
enum NavigationArea: string
{
    case MiEspacio = 'mi_espacio';
    case Academico = 'academico';
    case CargaAcademica = 'carga_academica';
    case ServiciosEscolares = 'servicios_escolares';
    case RecursosHumanos = 'recursos_humanos';
    case RecursosFinancieros = 'recursos_financieros';
    case Administracion = 'administracion';

    /**
     * Nombre para la interfaz.
     */
    public function label(): string
    {
        return match ($this) {
            self::MiEspacio => 'Mi espacio',
            self::Academico => 'Académico',
            self::CargaAcademica => 'Carga académica',
            self::ServiciosEscolares => 'Servicios escolares',
            self::RecursosHumanos => 'Recursos humanos',
            self::RecursosFinancieros => 'Recursos financieros',
            self::Administracion => 'Administración',
        };
    }

    /**
     * Heroicon de MaryUI del área.
     */
    public function icon(): string
    {
        return match ($this) {
            self::MiEspacio => 'o-user-circle',
            self::Academico => 'o-academic-cap',
            self::CargaAcademica => 'o-table-cells',
            self::ServiciosEscolares => 'o-user-group',
            self::RecursosHumanos => 'o-identification',
            self::RecursosFinancieros => 'o-banknotes',
            self::Administracion => 'o-cog-6-tooth',
        };
    }
}
