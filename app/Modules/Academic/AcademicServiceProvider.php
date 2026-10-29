<?php

namespace App\Modules\Academic;

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;

/**
 * Módulo Academic: departamentos y sus jefes, carreras, planes de estudio, materias, retícula y
 * seriación (docs/dominio/modelo-de-datos.md, "Academic"). Depende solo de Core.
 */
final class AcademicServiceProvider extends ModuleServiceProvider
{
    public function navigation(): array
    {
        return [
            new NavigationItem('Departamentos', 'academic.departments', 'o-building-library', NavigationArea::Academico,
                ability: 'viewAny', arguments: [Department::class]),
            new NavigationItem('Materias', 'academic.subjects', 'o-book-open', NavigationArea::Academico,
                ability: 'viewAny', arguments: [Subject::class]),
        ];
    }
}
