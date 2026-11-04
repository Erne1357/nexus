<?php

namespace App\Modules\Faculty;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\UnitAdscriptions;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Faculty\Listeners\BlockDepartmentDeletionWithTeachers;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\TeacherUnitAdscriptions;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Faculty: docentes, sus adscripciones a departamentos y sus plazas (docs/dominio/modelo-de-datos.md,
 * "Faculty"). Depende de Core y Academic.
 */
final class FacultyServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        // Las adscripciones de los docentes, para contar las personas de cada unidad del Organigrama (reemplaza el de Core).
        $this->app->bind(UnitAdscriptions::class, TeacherUnitAdscriptions::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(DepartmentDeleting::class, BlockDepartmentDeletionWithTeachers::class);
    }

    public function navigation(): array
    {
        return [
            new NavigationItem('Docentes', 'faculty.teachers', 'o-users', NavigationArea::RecursosHumanos,
                ability: 'viewAny', arguments: [Teacher::class], childRoutes: ['faculty.teachers.show', 'faculty.teachers.availability']),
        ];
    }

    public function permissions(): array
    {
        return [FacultyPermission::class];
    }

    /**
     * La Subdirección, a todos los docentes (`faculty.teacher.view_all`), además de la baja y adscribir a uno que ya
     * existe; ella y los jefes de departamento y de proyecto docente, a los de los departamentos que cubren. Nombrar
     * jefes, dar de alta usuarios y restablecer contraseñas no se concede: es solo del admin.
     */
    public function grants(): array
    {
        $byDepartment = [
            FacultyPermission::TeacherView,
            FacultyPermission::TeacherCreate,
            FacultyPermission::TeacherUpdate,
            FacultyPermission::TeacherManagePositions,
            FacultyPermission::TeacherManageAvailability,
            FacultyPermission::TeacherManageExternalLoads,
            FacultyPermission::TeacherQualificationManage,
            FacultyPermission::TeacherDepartmentRemove,
            FacultyPermission::TeacherDepartmentSetPrimary,
        ];

        return [
            Role::Subdireccion->value => [
                FacultyPermission::TeacherViewAll,
                ...$byDepartment,
                FacultyPermission::TeacherAdscribe,
                FacultyPermission::TeacherDeactivate,
            ],
            Role::JefeDepartamento->value => $byDepartment,
            Role::JefeProyectoDocente->value => $byDepartment,
        ];
    }
}
