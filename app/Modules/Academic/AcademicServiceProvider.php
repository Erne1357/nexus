<?php

namespace App\Modules\Academic;

use App\Modules\Academic\Authorization\AcademicScopeLabels;
use App\Modules\Academic\Console\ExcludeTeacherHoursCommand;
use App\Modules\Academic\Console\MarkCurrentSpecialtiesCommand;
use App\Modules\Academic\Console\PositionMailboxesFromDepartmentsCommand;
use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Academic\Enums\AcademicPermission;
use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
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

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([ExcludeTeacherHoursCommand::class, MarkCurrentSpecialtiesCommand::class, PositionMailboxesFromDepartmentsCommand::class]);
        }
    }

    public function permissions(): array
    {
        return [AcademicPermission::class];
    }

    /**
     * El jefe de departamento valida las materias por validar de la migración del SII (D19); el permiso aplica solo a
     * Servicios Escolares (AZ-02, AcademicPermission::appliesTo()), así que solo lo ejerce el jefe de esa unidad, y
     * StudyPlanPolicy además lo pregunta sobre ella. El directivo consulta los cuatro catálogos en todo el Tec (AZ-03).
     */
    public function grants(): array
    {
        return [
            Role::Directivo->value => [
                AcademicPermission::DepartmentView,
                AcademicPermission::CareerView,
                AcademicPermission::SubjectView,
                AcademicPermission::StudyPlanView,
            ],
            Role::JefeDepartamento->value => [AcademicPermission::PlacementValidate, AcademicPermission::SubjectSpaceSet],
        ];
    }

    /**
     * Candados (CF-03, confirmado el 15 de noviembre de 2026): borrar una carrera o un departamento termina los
     * nombramientos de otros (CloseRoleScopes), así que solo lo hace el admin y no se da a otro puesto.
     */
    public function adminOnly(): array
    {
        return [AcademicPermission::CareerDelete, AcademicPermission::DepartmentDelete];
    }

    /**
     * Los catálogos van en Administración. «Materias por colocar» (tarea 1.21, supuesto por confirmar) es la única
     * tarea de Servicios Escolares en Academic, las materias por validar de la migración del SII (D19): va en su área,
     * solo para quien las coloca, después de Alumnos, y abre la lista de planes con el filtro «Por validar».
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Departamentos', 'academic.departments', 'o-building-library', NavigationArea::Administracion, group: NavigationGroup::Catalogos,
                ability: 'viewAny', arguments: [Department::class]),
            new NavigationItem('Carreras', 'academic.careers', 'o-academic-cap', NavigationArea::Administracion, group: NavigationGroup::Catalogos,
                ability: 'viewAny', arguments: [Career::class]),
            new NavigationItem('Materias', 'academic.subjects', 'o-book-open', NavigationArea::Administracion, group: NavigationGroup::Catalogos,
                ability: 'viewAny', arguments: [Subject::class]),
            new NavigationItem('Planes de estudio', 'academic.plans', 'o-rectangle-group', NavigationArea::Administracion, group: NavigationGroup::Catalogos,
                ability: 'viewAny', arguments: [StudyPlan::class], childRoutes: ['academic.plans.show']),
            new NavigationItem('Materias por colocar', 'academic.placements', 'o-queue-list', NavigationArea::ServiciosEscolares,
                ability: 'validatePlacements', arguments: [StudyPlan::class], order: 10),
        ];
    }

    /**
     * Demo por puntos: el catálogo académico de los campus del volumen y sus usuarios de prueba (subdirección, jefes,
     * jefes de proyecto docente y coordinadores) llevan a «catálogos»; hay jefe para todo departamento activo, porque
     * cada uno valida sus catálogos con su jefe (CV-S1).
     */
    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::Catalogs, 'Catálogo académico y usuarios de prueba', function (DemoContext $demo): void {
                $demo->runSeeder(AcademicDemoSeeder::class, ['campuses' => $demo->campuses]);
                $demo->runSeeder(DemoStaffSeeder::class, ['campuses' => $demo->campuses, 'allDepartments' => true]);
            }),
        ];
    }

    public function activityAttributes(): array
    {
        return [
            Subject::class => ['kind' => ['Tipo de materia', SubjectKind::class], 'theory_hours' => 'Horas de teoría', 'practice_hours' => 'Horas de práctica', 'credits' => 'Créditos', 'needs_lab' => 'Requiere laboratorio', 'required_space' => ['Tipo de espacio', RequiredSpace::class], 'counts_teacher_hours' => 'Cuenta en la carga del docente'],
            SubjectPrerequisite::class => ['kind' => ['Tipo de seriación', PrerequisiteKind::class]],
            StudyPlan::class => ['version' => 'Versión', 'semesters_count' => 'Semestres', 'total_credits' => 'Créditos totales', 'is_historical' => 'Histórico', 'valid_from' => 'Vigente desde'],
            StudyPlanSubject::class => ['is_required' => 'Obligatoria', 'position' => 'Posición'],
        ];
    }
}
