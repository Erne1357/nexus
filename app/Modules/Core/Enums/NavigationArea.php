<?php

namespace App\Modules\Core\Enums;

/**
 * Área del menú a la que pertenece una entrada (ADR-0015, DESIGN.md "Navigation", MN-01). El orden de
 * los casos es el orden del menú; solo se muestran las áreas con al menos una entrada visible para
 * el usuario. Dentro de un área, las entradas pueden ir en grupos (NavigationGroup).
 */
enum NavigationArea: string
{
    case MiEspacio = 'mi_espacio';
    case Academico = 'academico';
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
            self::ServiciosEscolares => 'o-user-group',
            self::RecursosHumanos => 'o-identification',
            self::RecursosFinancieros => 'o-banknotes',
            self::Administracion => 'o-cog-6-tooth',
        };
    }
}
