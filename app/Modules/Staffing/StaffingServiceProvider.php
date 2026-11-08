<?php

namespace App\Modules\Staffing;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Staffing\Enums\StaffingPermission;

/**
 * Módulo Staffing: ranking docente y asignación de docentes a los grupos (docs/dominio/modelo-de-datos.md,
 * "Staffing"). Está a la derecha de Scheduling (ADR-0020); puede usar Scheduling, Faculty, AcademicLoad, Academic y Core.
 */
final class StaffingServiceProvider extends ModuleServiceProvider
{
    public function navigation(): array
    {
        return [
            new NavigationItem('Mi carga', 'staffing.my-load', 'o-calendar-days', NavigationArea::MiEspacio,
                visible: static fn (Actor $context): bool => $context->allowsAny(StaffingPermission::MyLoadView)),
            new NavigationItem('Oferta del departamento', 'staffing.offer', 'o-squares-2x2', NavigationArea::MiEspacio,
                visible: static fn (Actor $context): bool => $context->allowsAny(StaffingPermission::OfferView)),
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
            Role::Docente->value => [
                StaffingPermission::OfferView,
                StaffingPermission::MyLoadView,
            ],
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
