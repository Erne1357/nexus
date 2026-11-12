<?php

namespace App\Modules\Students;

use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Events\PlanCareerChanging;
use App\Modules\Academic\Events\SubjectDeactivating;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Students\Database\Seeders\StudentsDemoSeeder;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Listeners\BlockPlanCareerChangeWithStudents;
use App\Modules\Students\Listeners\BlockSubjectDeactivationWhileNeeded;
use App\Modules\Students\Listeners\RecomputeProgressOnCurriculumChange;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Students: alumnos, su kardex y su avance reticular (docs/dominio/modelo-de-datos.md, "Students").
 * Depende de Academic y Core. Se llena desde Imports: en R1 y R2 es de solo lectura.
 *
 * Escucha a Academic, que no depende de Students: veta desactivar una materia que todavía necesitan
 * alumnos y recalcula el avance cuando cambia la retícula o una materia.
 */
final class StudentsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(SubjectDeactivating::class, BlockSubjectDeactivationWhileNeeded::class);
        Event::listen(PlanCareerChanging::class, BlockPlanCareerChangeWithStudents::class);
        Event::listen(CurriculumChanged::class, RecomputeProgressOnCurriculumChange::class);
    }

    public function navigation(): array
    {
        return [
            new NavigationItem('Mis datos', 'students.my-record', 'o-identification', NavigationArea::MiEspacio,
                visible: static fn (Actor $context): bool => $context->allowsAny(StudentsPermission::MyRecordView)),
            new NavigationItem('Alumnos', 'students.index', 'o-academic-cap', NavigationArea::ServiciosEscolares,
                ability: 'viewAny', arguments: [Student::class], childRoutes: ['students.show']),
        ];
    }

    public function permissions(): array
    {
        return [StudentsPermission::class];
    }

    /**
     * Consultan alumnos la Subdirección, los jefes y los coordinadores; el alumno, solo sus datos (AC-15).
     */
    public function grants(): array
    {
        return [
            Role::Alumno->value => [StudentsPermission::MyRecordView],
            Role::Subdireccion->value => [StudentsPermission::StudentView],
            Role::JefeDepartamento->value => [StudentsPermission::StudentView],
            Role::Coordinador->value => [StudentsPermission::StudentView],
        ];
    }

    /**
     * «Mis datos» es del alumno sobre lo suyo: el admin no es alumno, así que no lo ve en el menú.
     */
    public function adminDenies(): array
    {
        return [StudentsPermission::MyRecordView];
    }

    /**
     * Demo por puntos: los alumnos de cada oferta de carrera, su kárdex y su avance llevan a «catálogos», con los
     * mismos números que DemoSeeder y MassiveSeeder.
     */
    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::Catalogs, 'Alumnos, kárdex y avance', fn (DemoContext $demo) => $demo->runSeeder(StudentsDemoSeeder::class, [
                'studentsPerOffering' => $demo->volume === DemoVolume::Massive ? ['C1' => 670, 'C2' => 660] : ['C2' => 35],
                'seed' => $demo->seed,
            ])),
        ];
    }

    public function demoChecks(): array
    {
        return [
            new DemoCheck(DemoCheckpoint::staged(), 'Hay alumnos con kárdex', fn (): true|string => KardexRecord::query()->exists()
                ? true
                : 'No hay renglones de kárdex.'),
        ];
    }
}
