<?php

namespace App\Modules\Staffing;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Events\TeacherAccessChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Staffing\Console\RankTerm;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Listeners\RerankOnFacultyChange;
use App\Modules\Staffing\Listeners\RerankOnGroupChange;
use App\Modules\Staffing\Listeners\RerankOnGroupsCreated;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Staffing: ranking docente y asignación de docentes a los grupos (docs/dominio/modelo-de-datos.md,
 * "Staffing"). Está a la derecha de Scheduling (ADR-0020); puede usar Scheduling, Faculty, AcademicLoad, Academic y Core.
 *
 * Escucha a Scheduling y a Faculty, que no dependen de Staffing, para recalcular el ranking docente en la cola
 * (ComputeRankings) cuando cambia cualquiera de sus entradas.
 */
final class StaffingServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([RankTerm::class]);
        }

        Event::listen(GroupsCreated::class, RerankOnGroupsCreated::class);
        Event::listen([GroupScheduleChanged::class, GroupCancelled::class, GroupTeacherChanged::class], RerankOnGroupChange::class);
        Event::listen([
            PositionChanged::class, ExternalLoadChanged::class, AvailabilityChanged::class, QualificationChanged::class,
            AdscriptionChanged::class, TeacherUpdated::class, TeacherAccessChanged::class,
        ], RerankOnFacultyChange::class);
    }

    /**
     * «Asignación docente» para quienes ven el ranking de algún departamento (StaffingPermission::RankingView).
     *
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Asignación docente', 'staffing.assign', 'o-user-plus', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(StaffingPermission::RankingView)),
        ];
    }

    /**
     * @return list<class-string>
     */
    public function permissions(): array
    {
        return [StaffingPermission::class];
    }

    /**
     * Ven el ranking los jefes, el jefe de proyecto docente y la Subdirección; asignan los jefes (reglas-de-negocio.md,
     * "Aprobaciones y permisos").
     */
    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [
                StaffingPermission::RankingView,
            ],
            Role::JefeDepartamento->value => [
                StaffingPermission::RankingView,
                StaffingPermission::TeacherAssign,
            ],
            Role::JefeProyectoDocente->value => [
                StaffingPermission::RankingView,
                StaffingPermission::TeacherAssign,
            ],
        ];
    }
}
