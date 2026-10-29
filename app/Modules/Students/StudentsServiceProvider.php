<?php

namespace App\Modules\Students;

use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Events\PlanCareerChanging;
use App\Modules\Academic\Events\SubjectDeactivating;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Students\Listeners\BlockPlanCareerChangeWithStudents;
use App\Modules\Students\Listeners\BlockSubjectDeactivationWhileNeeded;
use App\Modules\Students\Listeners\RecomputeProgressOnCurriculumChange;
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
            new NavigationItem('Alumnos', 'students.index', 'o-academic-cap', NavigationArea::ServiciosEscolares,
                ability: 'viewAny', arguments: [Student::class], childRoutes: ['students.show']),
        ];
    }
}
