<?php

namespace App\Modules\Academic;

use App\Modules\Core\ModuleServiceProvider;

/**
 * Módulo Academic: departamentos y sus jefes, carreras, planes de estudio, materias, retícula y
 * seriación (docs/dominio/modelo-de-datos.md, "Academic"). Depende solo de Core.
 */
final class AcademicServiceProvider extends ModuleServiceProvider
{
    public function navigation(): array
    {
        return [];
    }
}
