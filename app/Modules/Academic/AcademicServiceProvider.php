<?php

namespace App\Modules\Academic;

use App\Modules\Academic\Authorization\AcademicScopeLabels;
use App\Modules\Academic\Enums\AcademicPermission;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;

/**
 * Módulo Academic: departamentos y sus jefes, carreras, planes de estudio, materias, retícula y
 * seriación (docs/dominio/modelo-de-datos.md, "Academic"). Depende solo de Core.
 */
final class AcademicServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        // Los códigos de carrera para el resumen de puestos de la credencial (reemplaza el de Core).
        $this->app->bind(ScopeLabels::class, AcademicScopeLabels::class);
    }

    public function permissions(): array
    {
        return [AcademicPermission::class];
    }

    /**
     * El jefe de departamento valida las materias por validar de la migración del SII; el permiso declara la unidad y
     * StudyPlanPolicy lo pregunta sobre Servicios Escolares, así que solo lo ejerce el jefe de esa unidad (D19).
     */
    public function grants(): array
    {
        return [
            Role::JefeDepartamento->value => [AcademicPermission::PlacementValidate],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavigationItem('Departamentos', 'academic.departments', 'o-building-library', NavigationArea::Academico,
                ability: 'viewAny', arguments: [Department::class]),
            new NavigationItem('Carreras', 'academic.careers', 'o-academic-cap', NavigationArea::Academico,
                ability: 'viewAny', arguments: [Career::class]),
            new NavigationItem('Materias', 'academic.subjects', 'o-book-open', NavigationArea::Academico,
                ability: 'viewAny', arguments: [Subject::class]),
            new NavigationItem('Planes de estudio', 'academic.plans', 'o-rectangle-group', NavigationArea::Academico,
                ability: 'viewAny', arguments: [StudyPlan::class], childRoutes: ['academic.plans.show']),
        ];
    }
}
