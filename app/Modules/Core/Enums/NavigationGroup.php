<?php

namespace App\Modules\Core\Enums;

/**
 * Grupo del menú dentro de un área (DESIGN.md "Navigation", MN-01): Académico se agrupa por etapa del
 * semestre, en el orden de Stage, y Administración por propósito. El orden de los casos es el orden del
 * menú dentro de su área. El encabezado del grupo solo se muestra si el área tiene al menos dos grupos
 * visibles y suficientes entradas (Navigation::visibleByArea).
 */
enum NavigationGroup: string
{
    case Validacion = 'validacion';
    case Propuesta = 'propuesta';
    case Horarios = 'horarios';
    case Asignacion = 'asignacion';
    case CierrePublicacion = 'cierre_publicacion';
    case Institucion = 'institucion';
    case Catalogos = 'catalogos';
    case Datos = 'datos';

    /**
     * Nombre para la interfaz.
     */
    public function label(): string
    {
        return match ($this) {
            self::Validacion => 'Validación',
            self::Propuesta => 'Propuesta',
            self::Horarios => 'Horarios',
            self::Asignacion => 'Asignación',
            self::CierrePublicacion => 'Cierre y publicación',
            self::Institucion => 'Institución',
            self::Catalogos => 'Catálogos',
            self::Datos => 'Datos',
        };
    }

    /**
     * El área a la que pertenece: una entrada solo puede ir en un grupo de su área.
     */
    public function area(): NavigationArea
    {
        return match ($this) {
            self::Validacion, self::Propuesta, self::Horarios, self::Asignacion, self::CierrePublicacion => NavigationArea::Academico,
            self::Institucion, self::Catalogos, self::Datos => NavigationArea::Administracion,
        };
    }
}
