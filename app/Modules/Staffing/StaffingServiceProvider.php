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
