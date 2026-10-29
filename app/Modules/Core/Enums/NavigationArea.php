<?php

namespace App\Modules\Core\Enums;

/**
 * Área del menú a la que pertenece una entrada de la barra lateral (ADR-0015, DESIGN.md
 * "Navigation"). El orden de los casos es el orden del menú; solo se muestran las áreas con al
 * menos una entrada visible para el usuario.
 */
enum NavigationArea: string
{
    case Academico = 'academico';
    case ServiciosEscolares = 'servicios_escolares';
    case RecursosHumanos = 'recursos_humanos';
    case RecursosFinancieros = 'recursos_financieros';
    case Datos = 'datos';

    /**
     * Nombre para la interfaz.
     */
    public function label(): string
    {
        return match ($this) {
            self::Academico => 'Académico',
            self::ServiciosEscolares => 'Servicios escolares',
            self::RecursosHumanos => 'Recursos humanos',
            self::RecursosFinancieros => 'Recursos financieros',
            self::Datos => 'Datos',
        };
    }

    /**
     * Heroicon de MaryUI del área.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Academico => 'o-academic-cap',
            self::ServiciosEscolares => 'o-user-group',
            self::RecursosHumanos => 'o-identification',
            self::RecursosFinancieros => 'o-banknotes',
            self::Datos => 'o-circle-stack',
        };
    }
}
