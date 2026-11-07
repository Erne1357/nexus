<?php

namespace App\Modules\Scheduling;

use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Events\ProposalReviewAccepted;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Facilities\Events\RoomBlockAdded;
use App\Modules\Facilities\Events\RoomBlockRemoved;
use App\Modules\Facilities\Events\RoomUpdated;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Scheduling\Console\RecomputeConflicts;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Listeners\AssignCodeOnScheduleChange;
use App\Modules\Scheduling\Listeners\BlockStaffingWithHardConflicts;
use App\Modules\Scheduling\Listeners\CreateGroupsOnPartAccepted;
use App\Modules\Scheduling\Listeners\QueueConflictRecompute;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Scheduling: grupos, sesiones, cupos por carrera, validador de restricciones, editor de horarios, salones y
 * tablero de choques (docs/dominio/modelo-de-datos.md, "Scheduling"). Depende de AcademicLoad, Facilities y Faculty,
 * y de lo que está a su izquierda (ADR-0020); Staffing, a su derecha, escucha sus eventos.
 */
final class SchedulingServiceProvider extends ModuleServiceProvider
{
    /**
     * Lo que dispara el recálculo de choques: sesiones, docentes y grupos de Scheduling, salones y bloqueos de
     * Facilities, y disponibilidad, horas externas y plazas de Faculty.
     */
    private const array RECOMPUTE_ON = [
        GroupsCreated::class,
        GroupScheduleChanged::class,
        GroupTeacherChanged::class,
        GroupCancelled::class,
        RoomUpdated::class,
        RoomBlockAdded::class,
        RoomBlockRemoved::class,
        AvailabilityChanged::class,
        ExternalLoadChanged::class,
        PositionChanged::class,
    ];

    public function boot(): void
    {
        parent::boot();

        foreach (self::RECOMPUTE_ON as $event) {
            Event::listen($event, QueueConflictRecompute::class);
        }

        Event::listen(ProposalReviewAccepted::class, CreateGroupsOnPartAccepted::class);
        Event::listen(GroupScheduleChanged::class, AssignCodeOnScheduleChange::class);

        Event::listen(LoadTransitioning::class, BlockStaffingWithHardConflicts::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RecomputeConflicts::class]);
        }
    }

    /**
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Choques y avance', 'scheduling.conflicts', 'o-exclamation-triangle', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(SchedulingPermission::ScheduleView)),
        ];
    }

    /**
     * @return list<class-string>
     */
    public function permissions(): array
    {
        return [SchedulingPermission::class];
    }

    /**
     * Quienes ven la carga ven los horarios y el tablero, y el coordinador los de su carrera en su campus (confirmado
     * el 7 de noviembre de 2026). Crear grupos y editar sesiones es del coordinador de la carrera dueña y del jefe del
     * departamento; asignar salón y repartir cupos, del jefe del departamento que imparte.
     */
    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [
                SchedulingPermission::ScheduleView,
            ],
            Role::JefeDepartamento->value => [
                SchedulingPermission::ScheduleView,
                SchedulingPermission::GroupManage,
                SchedulingPermission::SessionEdit,
                SchedulingPermission::RoomAssign,
                SchedulingPermission::QuotaManage,
            ],
            Role::JefeProyectoDocente->value => [
                SchedulingPermission::ScheduleView,
            ],
            Role::Coordinador->value => [
                SchedulingPermission::ScheduleView,
                SchedulingPermission::GroupManage,
                SchedulingPermission::SessionEdit,
            ],
        ];
    }
}
