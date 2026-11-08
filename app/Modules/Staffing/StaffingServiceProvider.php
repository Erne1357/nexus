<?php

namespace App\Modules\Staffing;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Faculty\Models\Teacher;
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
        return [
            new NavigationItem('Mi carga', 'staffing.my-load', 'o-calendar-days', NavigationArea::MiEspacio,
                visible: static fn (Actor $context): bool => self::isTeacher($context) && $context->allowsAny(StaffingPermission::MyLoadView)),
            new NavigationItem('Oferta del departamento', 'staffing.offer', 'o-squares-2x2', NavigationArea::MiEspacio,
                visible: static fn (Actor $context): bool => self::isTeacher($context) && $context->allowsAny(StaffingPermission::OfferView)),
        ];
    }

    /**
     * «Mi espacio» es de quien tiene una ficha de docente activa: el admin, que lo puede todo, no la tiene, así que no
     * lo ve en el menú (la página igual responde si entra por la URL).
     */
    private static function isTeacher(Actor $context): bool
    {
        $user = $context->user();

        return $user !== null && Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->exists();
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
            Role::Docente->value => [
                StaffingPermission::OfferView,
                StaffingPermission::MyLoadView,
            ],
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
