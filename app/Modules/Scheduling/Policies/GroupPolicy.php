<?php

namespace App\Modules\Scheduling\Policies;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Models\Group;

/**
 * Quién ve y cambia un grupo, según docs/dominio/reglas-de-negocio.md, "Aprobaciones y permisos" (filas «Ver los
 * horarios y el tablero de choques», «Crear grupos y editar sesiones», «Definir cupos por carrera» y «Asignar
 * salón»). El objetivo es la modalidad del grupo, su campus (solo donde el permiso lo declara: se ve la modalidad
 * entera y se escribe en el campus, MS6), su departamento (el de la materia) y su carrera dueña:
 *
 * - Lo ven quienes ven la carga (Subdirección, el jefe de su departamento en cualquier campus, el jefe de proyecto
 *   docente de su departamento) y el coordinador de su carrera dueña. Se ve la modalidad entera aunque el puesto
 *   tenga un campus (MS6).
 * - Lo gestionan y acomodan sus sesiones el coordinador de su carrera dueña y el jefe de su departamento.
 * - Asignan salón y reparten cupos el jefe de su departamento.
 * - Al escribir, el puesto con campus solo lo hace en ese campus (MS6).
 * - Justifica que se quede sin docente o sin salón al cerrar (HC-15) quien cierra la carga de su departamento
 *   (`academic_load.load.close`: el jefe y Subdirección; P2, confirmado el 10 de noviembre de 2026).
 *
 * La condición de estado de la carga (`proposed`, `staffing` o `adjusting`) la revisa la Action que escribe.
 */
final class GroupPolicy
{
    use AuthorizesActor;

    public function view(User $user, Group $group): bool
    {
        return $this->can($user, SchedulingPermission::ScheduleView, $group);
    }

    public function manage(User $user, Group $group): bool
    {
        return $this->can($user, SchedulingPermission::GroupManage, $group);
    }

    public function editSessions(User $user, Group $group): bool
    {
        return $this->can($user, SchedulingPermission::SessionEdit, $group);
    }

    public function assignRoom(User $user, Group $group): bool
    {
        return $this->can($user, SchedulingPermission::RoomAssign, $group);
    }

    public function manageQuotas(User $user, Group $group): bool
    {
        return $this->can($user, SchedulingPermission::QuotaManage, $group);
    }

    public function justify(User $user, Group $group): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::LoadClose, Target::of(modality: $group->modality_id, unit: $group->department_id));
    }

    private function can(User $user, SchedulingPermission $permission, Group $group): bool
    {
        return $this->actor($user)->allows($permission, Target::of(
            modality: $group->modality_id,
            campus: in_array(Dimension::Campus, $permission->dimensions(), true) ? $group->campus_id : null,
            unit: $group->department_id,
            career: $group->owner_career_id,
        ));
    }
}
