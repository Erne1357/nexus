<?php

namespace App\Modules\Scheduling;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Events\AgreementAccepted;
use App\Modules\AcademicLoad\Events\AgreementRejected;
use App\Modules\AcademicLoad\Events\AgreementRequested;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Listeners\NotifyDeciderOnAgreementRequested;
use App\Modules\Scheduling\Listeners\NotifyRequesterOnAgreementDecided;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Scheduling: grupos, sesiones, cupos por carrera, validador de restricciones, editor de horarios, salones y
 * tablero de choques (docs/dominio/modelo-de-datos.md, "Scheduling"). Depende de AcademicLoad, Facilities y Faculty,
 * y de lo que está a su izquierda (ADR-0020); Staffing, a su derecha, escucha sus eventos.
 */
final class SchedulingServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(AgreementRequested::class, NotifyDeciderOnAgreementRequested::class);
        Event::listen(AgreementAccepted::class, NotifyRequesterOnAgreementDecided::class);
        Event::listen(AgreementRejected::class, NotifyRequesterOnAgreementDecided::class);
    }

    /**
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Acuerdos', 'scheduling.agreements', 'o-arrows-right-left', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::AgreementView)),
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
