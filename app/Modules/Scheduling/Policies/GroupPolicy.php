<?php

namespace App\Modules\Scheduling\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Models\Group;

/**
 * Quién ve y cambia un grupo, según docs/dominio/reglas-de-negocio.md, "Aprobaciones y permisos" (filas «Ver los
 * horarios y el tablero de choques», «Crear grupos y editar sesiones», «Definir cupos por carrera» y «Asignar
 * salón»). El objetivo es el campus del grupo, su departamento (el de la materia) y su carrera dueña:
 *
 * - Lo ven quienes ven la carga (Subdirección, el jefe de su departamento en cualquier campus, el jefe de proyecto
 *   docente de su departamento en su campus) y el coordinador de su carrera dueña en su campus.
 * - Lo gestionan y acomodan sus sesiones el coordinador de su carrera dueña y el jefe de su departamento.
 * - Asignan salón y reparten cupos el jefe de su departamento.
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

    private function can(User $user, SchedulingPermission $permission, Group $group): bool
    {
        return $this->actor($user)->allows($permission, Target::of(
            campus: $group->campus_id,
            unit: $group->department_id,
            career: $group->owner_career_id,
        ));
    }
}
