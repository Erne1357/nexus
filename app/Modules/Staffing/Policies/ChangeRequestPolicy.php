<?php

namespace App\Modules\Staffing\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\ChangeRequest;

/**
 * Quién pide, ve, decide y aplica una solicitud de cambio (reglas-de-negocio.md, "Solicitudes de cambio"):
 *
 * - Pide el docente asignado hoy al grupo (permiso personal; el dueño es su usuario) o el coordinador de la carrera
 *   dueña del grupo en su campus.
 * - Ve quien la pidió, los jefes, el jefe de proyecto docente, la Subdirección y quien la aplicaría.
 * - Decide el jefe del departamento del grupo.
 * - Aplica, según el tipo (confirmado el 7 de noviembre de 2026): el horario, el coordinador de la carrera dueña; el
 *   salón, el jefe del departamento.
 *
 * Los estados (pendiente, aprobada…) los exigen las Actions. El admin pasa por la concesión implícita.
 */
final class ChangeRequestPolicy
{
    use AuthorizesActor;

    public function create(User $user, Group $group): bool
    {
        $group->loadMissing('teacher');
        $teacherUserId = $group->teacher?->user_id;

        if ($teacherUserId !== null && $this->actor($user)->allows(StaffingPermission::ChangeRequestRequestOwn, Target::ownedBy($teacherUserId))) {
            return true;
        }

        return $this->actor($user)->allows(StaffingPermission::ChangeRequestRequest, Target::of(modality: $group->modality_id, campus: $group->campus_id, career: $group->owner_career_id));
    }

    public function view(User $user, ChangeRequest $request): bool
    {
        return $request->requested_by === $user->getKey()
            || $this->actor($user)->allows(StaffingPermission::ChangeRequestView, $this->unitTarget($request))
            || $this->decide($user, $request)
            || $this->apply($user, $request);
    }

    public function decide(User $user, ChangeRequest $request): bool
    {
        return $this->actor($user)->allows(StaffingPermission::ChangeRequestDecide, $this->unitTarget($request));
    }

    public function apply(User $user, ChangeRequest $request): bool
    {
        if ($request->kind === ChangeRequestKind::Room) {
            return $this->actor($user)->allows(StaffingPermission::ChangeRequestApplyRoom, Target::of(modality: $request->modality_id, campus: $request->campus_id, unit: $request->department_id));
        }

        $request->loadMissing('group');

        return $this->actor($user)->allows(StaffingPermission::ChangeRequestApplySchedule, Target::of(modality: $request->modality_id, campus: $request->campus_id, career: $request->group->owner_career_id));
    }

    private function unitTarget(ChangeRequest $request): Target
    {
        return Target::of(modality: $request->modality_id, unit: $request->department_id);
    }
}
