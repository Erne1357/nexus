<?php

namespace App\Modules\Academic;

use App\Modules\Academic\Authorization\AcademicScopeLabels;
use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Academic\Enums\AcademicPermission;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
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
     * El jefe de departamento valida las materias por validar de la migración del SII (D19); el permiso aplica solo a
     * Servicios Escolares (AZ-02, AcademicPermission::appliesTo()), así que solo lo ejerce el jefe de esa unidad, y
     * StudyPlanPolicy además lo pregunta sobre ella.
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

    /**
     * Demo por puntos: el catálogo académico de los campus del volumen y sus usuarios de prueba (subdirección, jefes,
     * jefes de proyecto docente y coordinadores) llevan a «catálogos».
     */
    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::Catalogs, 'Catálogo académico y usuarios de prueba', function (DemoContext $demo): void {
                $demo->runSeeder(AcademicDemoSeeder::class, ['campuses' => $demo->campuses]);
                $demo->runSeeder(DemoStaffSeeder::class, ['campuses' => $demo->campuses]);
            }),
        ];
    }
}
