<?php

namespace App\Modules\Staffing;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Policies\ChangeRequestPolicy;

/**
 * Módulo Staffing: ranking docente y asignación de docentes a los grupos (docs/dominio/modelo-de-datos.md,
 * "Staffing"). Está a la derecha de Scheduling (ADR-0020); puede usar Scheduling, Faculty, AcademicLoad, Academic y Core.
 */
final class StaffingServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        ChangeRequest::class => ChangeRequestPolicy::class,
    ];

    public function navigation(): array
    {
        return [];
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
     * "Aprobaciones y permisos"). Solicitudes de cambio: piden el docente del grupo y el coordinador de su carrera;
     * ven los jefes, el jefe de proyecto docente y la Subdirección; decide el jefe del departamento; aplica el
     * coordinador (horario) o el jefe (salón).
     */
    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [
                StaffingPermission::RankingView,
                StaffingPermission::ChangeRequestView,
            ],
            Role::JefeDepartamento->value => [
                StaffingPermission::RankingView,
                StaffingPermission::TeacherAssign,
                StaffingPermission::ChangeRequestView,
                StaffingPermission::ChangeRequestDecide,
                StaffingPermission::ChangeRequestApplyRoom,
            ],
            Role::JefeProyectoDocente->value => [
                StaffingPermission::RankingView,
                StaffingPermission::TeacherAssign,
                StaffingPermission::ChangeRequestView,
            ],
            Role::Coordinador->value => [
                StaffingPermission::ChangeRequestRequest,
                StaffingPermission::ChangeRequestApplySchedule,
            ],
            Role::Docente->value => [
                StaffingPermission::ChangeRequestRequestOwn,
            ],
        ];
    }
}
