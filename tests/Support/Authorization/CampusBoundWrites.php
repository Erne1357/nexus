<?php

namespace Tests\Support\Authorization;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Staffing\Enums\StaffingPermission;

/**
 * AZ-S17 (MS6, supuesto por confirmar): los permisos de escritura sobre algo con campus, por módulo. Se lee a nivel de
 * modalidad y se escribe con campus, así que estos declaran Dimension::Campus. Lista explícita: un caso nuevo que escriba
 * algo con campus se agrega aquí. La usan las pruebas de arquitectura de cada módulo (tests/Arch/Modality) y
 * PermissionDimensionsTest, que solo deja leer sin campus a lo que no está aquí.
 */
final class CampusBoundWrites
{
    /**
     * Una fila de la propuesta (de un campus, M7) y el acuerdo de un préstamo de salón (el campus del salón) o de un
     * grupo compartido (el campus del grupo).
     *
     * @return list<AcademicLoadPermission>
     */
    public static function academicLoad(): array
    {
        return [
            AcademicLoadPermission::ProposalUpdate,
            AcademicLoadPermission::AgreementRequest,
        ];
    }

    /**
     * El grupo, sus sesiones, su salón y sus cupos: todo en el campus del grupo (M8).
     *
     * @return list<SchedulingPermission>
     */
    public static function scheduling(): array
    {
        return [
            SchedulingPermission::GroupManage,
            SchedulingPermission::SessionEdit,
            SchedulingPermission::RoomAssign,
            SchedulingPermission::QuotaManage,
        ];
    }

    /**
     * Asignar al docente y pedir o aplicar un cambio de un grupo, en el campus del grupo.
     *
     * @return list<StaffingPermission>
     */
    public static function staffing(): array
    {
        return [
            StaffingPermission::TeacherAssign,
            StaffingPermission::ChangeRequestRequest,
            StaffingPermission::ChangeRequestApplySchedule,
            StaffingPermission::ChangeRequestApplyRoom,
        ];
    }

    /**
     * @return list<Permission>
     */
    public static function all(): array
    {
        return [...self::academicLoad(), ...self::scheduling(), ...self::staffing()];
    }
}
