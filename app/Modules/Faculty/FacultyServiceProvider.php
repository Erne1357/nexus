<?php

namespace App\Modules\Faculty;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\UnitAdscriptions;
use App\Modules\Faculty\Database\Seeders\FacultyDemoSeeder;
use App\Modules\Faculty\Database\Seeders\TeacherCaptureDemoSeeder;
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
     * existe; ella y los jefes de departamento y de proyecto docente, a los de los departamentos que cubren. El jefe de
     * departamento, además, reenvía la invitación de acceso de sus docentes (AC-07). Nombrar jefes, dar de alta
     * usuarios y restablecer contraseñas no se concede: es solo del admin.
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
                FacultyPermission::TeacherDepartmentRemoveLast,
                FacultyPermission::TeacherAdscribe,
                FacultyPermission::TeacherDeactivate,
            ],
            Role::JefeDepartamento->value => [...$byDepartment, FacultyPermission::TeacherDepartmentRemoveLast, FacultyPermission::TeacherUserInvite],
            Role::JefeProyectoDocente->value => $byDepartment,
        ];
    }

    /**
     * Demo por puntos:
     * - a «catálogos», los docentes del volumen en modo proceso (FacultyDemoSeeder: más plantilla repartida por las
     *   horas de cada departamento y disponibilidad más holgada, para que en «salones» todo grupo tenga docente), sus
     *   usuarios y los jefes de proyecto docente;
     * - a «validación», la variedad de captura de Ene-Jun 2027 (unos sin disponibilidad, sin perfil o con horas externas);
     * - a «propuesta», la captura completa, como la deja el jefe antes de validar.
     */
    public function demoSteps(): array
    {
        $profile = fn (DemoContext $demo): string => $demo->volume === DemoVolume::Massive ? 'massive' : 'demo';

        return [
            new DemoStep(DemoCheckpoint::Catalogs, 'Docentes, sus usuarios y jefes de proyecto docente', fn (DemoContext $demo) => $demo->runSeeder(FacultyDemoSeeder::class, [
                'teachersPerCampus' => $demo->volume === DemoVolume::Massive ? ['C1' => 350, 'C2' => 250] : ['C2' => 120],
                'seed' => $demo->seed,
                'forProcess' => true,
            ])),
            new DemoStep(DemoCheckpoint::CatalogValidation, 'Captura docente con variedad', fn (DemoContext $demo) => $demo->runSeeder(TeacherCaptureDemoSeeder::class, ['profile' => $profile($demo)])),
            new DemoStep(DemoCheckpoint::Proposal, 'Captura docente completa', fn (DemoContext $demo) => $demo->runSeeder(TeacherCaptureDemoSeeder::class, ['profile' => $profile($demo), 'complete' => true])),
        ];
    }

    public function demoChecks(): array
    {
        return [
            new DemoCheck(DemoCheckpoint::staged(), 'Hay docentes activos con usuario', fn (): true|string => Teacher::query()->where('is_active', true)->whereNotNull('user_id')->exists()
                ? true
                : 'Ningún docente activo tiene usuario.'),
            new DemoCheck([DemoCheckpoint::CatalogValidation], 'Captura docente con variedad: hay docentes con y sin disponibilidad', function (DemoContext $demo): true|string {
                $missing = self::teachersWithoutAvailability($demo);
                $active = Teacher::query()->where('is_active', true)->count();

                return $missing > 0 && $missing < $active ? true : "{$missing} de {$active} docentes activos sin disponibilidad.";
            }),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Proposal), 'Todo docente activo tiene disponibilidad capturada', fn (DemoContext $demo): true|string => ($missing = self::teachersWithoutAvailability($demo)) === 0
                ? true
                : "{$missing} docentes activos sin disponibilidad."),
        ];
    }

    /**
     * Docentes activos sin ningún bloque de disponibilidad vivo en el periodo de la demo.
     */
    private static function teachersWithoutAvailability(DemoContext $demo): int
    {
        $termId = $demo->term()->getKey();

        return Teacher::query()->where('is_active', true)->whereDoesntHave('availability', fn ($query) => $query->where('term_id', $termId))->count();
    }
}
