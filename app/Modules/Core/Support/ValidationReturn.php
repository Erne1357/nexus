<?php

namespace App\Modules\Core\Support;

use Illuminate\Support\Str;

/**
 * El regreso a la validación de catálogos desde las páginas a las que se entra desde ella (ficha del docente,
 * disponibilidad, plan, carreras, materias, salones y docentes; tarea 1.24.3). La validación enlaza con `?desde=validacion`
 * más el departamento (`depto`, un uuid, o SCHOOL_SERVICES para las revisiones de Servicios Escolares, CV15) y el
 * catálogo (`cat`, de una lista fija) que tenía abiertos; aquí se validan
 * y se arma la ruta con `route()`: nunca se acepta una URL de la query (open redirect). Un valor desconocido se ignora y
 * la página conserva su regreso de siempre.
 *
 * Core no conoce el módulo de la validación: la ruta va por su nombre y los catálogos son los valores de su enum.
 */
final class ValidationReturn
{
    /** El único valor que activa el regreso. */
    public const string FROM = 'validacion';

    /**
     * El `depto` de las revisiones de Servicios Escolares, que no tienen departamento (CV15): el único valor que no es un
     * uuid y se acepta.
     */
    public const string SCHOOL_SERVICES = 'servicios-escolares';

    /** Los catálogos de la validación que se pueden volver a abrir. */
    public const array CATALOGS = ['teachers', 'rooms', 'subjects', 'study_plans', 'careers'];

    /**
     * Los parámetros de la query que llevan a una página de regreso a la validación, para el enlace de salida.
     *
     * @return array{desde: string, depto: string, cat: string}
     */
    public static function params(string $departmentId, string $catalog): array
    {
        return ['desde' => self::FROM, 'depto' => $departmentId, 'cat' => $catalog];
    }

    /**
     * La ruta de la validación en el departamento (o Servicios Escolares) y el catálogo de donde se salió, o null si
     * `$from` no es la marca fija o el departamento no es un uuid ni SCHOOL_SERVICES. Un catálogo fuera de la lista se
     * ignora (queda el departamento).
     */
    public static function url(?string $from, ?string $department, ?string $catalog): ?string
    {
        if ($from !== self::FROM || $department === null || (! Str::isUuid($department) && $department !== self::SCHOOL_SERVICES)) {
            return null;
        }

        $params = ['department' => $department];

        if (in_array($catalog, self::CATALOGS, true)) {
            $params['catalogo'] = $catalog;
        }

        return route('catalog-validation.review', $params);
    }
}
