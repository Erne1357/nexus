<?php

namespace App\Modules\Staffing;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Staffing\Console\RefreshTeacherLoadsCommand;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Listeners\QueueTeacherLoadRefresh;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Staffing: ranking docente y asignación de docentes a los grupos (docs/dominio/modelo-de-datos.md,
 * "Staffing"). Está a la derecha de Scheduling (ADR-0020); puede usar Scheduling, Faculty, AcademicLoad, Academic y Core.
 */
final class StaffingServiceProvider extends ModuleServiceProvider
{
    /**
     * Lo que recalcula `teacher_term_loads`: los grupos con docente de Scheduling y las plazas, horas externas,
     * datos y adscripciones de Faculty.
     */
    private const array REFRESH_LOADS_ON = [
        GroupTeacherChanged::class,
        GroupCancelled::class,
        GroupsCreated::class,
        PositionChanged::class,
        ExternalLoadChanged::class,
        TeacherUpdated::class,
        AdscriptionChanged::class,
    ];

    public function boot(): void
    {
        parent::boot();

        foreach (self::REFRESH_LOADS_ON as $event) {
            Event::listen($event, QueueTeacherLoadRefresh::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshTeacherLoadsCommand::class]);
        }
    }

    public function navigation(): array
    {
        return [
            new NavigationItem('Carga por docente', 'staffing.teacher-loads', 'o-user-group', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(StaffingPermission::TeacherLoadView)),
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
                StaffingPermission::TeacherLoadView,
            ],
            Role::JefeDepartamento->value => [
                StaffingPermission::RankingView,
                StaffingPermission::TeacherAssign,
                StaffingPermission::TeacherLoadView,
            ],
            Role::JefeProyectoDocente->value => [
                StaffingPermission::RankingView,
                StaffingPermission::TeacherAssign,
                StaffingPermission::TeacherLoadView,
            ],
        ];
    }
}
