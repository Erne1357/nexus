<?php

namespace App\Modules\Solver\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverPermission;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;

/**
 * Quién lanza, ve y acepta corridas del motor (tarea 6.2; reglas-de-negocio.md, «Aprobaciones y permisos»; decisión del
 * usuario del 17 de noviembre de 2026):
 *
 * - El jefe de departamento lanza y acepta las corridas de la carga de su departamento en su modalidad.
 * - El coordinador lanza corridas de su carrera para explorar y comparar; no las acepta.
 * - Subdirección ve todas.
 * - Solo se acepta una corrida terminada de un departamento: la de carrera no escribe el horario.
 * - Cancela (SV-03) o descarta una corrida quien la lanzó o quien puede lanzar ese alcance (tarea 6.8, revisión del
 *   Riel A en #587): el jefe, las de la carga de su departamento; el coordinador, las de su carrera. Estas dos solo
 *   dicen quién: el estado (en curso para cancelar, terminada para descartar) lo revisan CancelSolverRun y la Action de
 *   descartar (6.10), y la pantalla lo pregunta aparte.
 *
 * El admin pasa por la concesión implícita del registro.
 */
final class SolverRunPolicy
{
    use AuthorizesActor;

    public function view(User $user, SolverRun $run): bool
    {
        return $run->scope_kind === RunScope::Department
            ? $this->actor($user)->allows(SolverPermission::DepartmentRunView, Target::of(modality: $run->modality_id, unit: $run->department_id))
            : $this->actor($user)->allows(SolverPermission::CareerRunView, Target::of(modality: $run->modality_id, career: $run->career_id));
    }

    public function launchForDepartment(User $user, string $modalityId, string $departmentId): bool
    {
        return $this->actor($user)->allows(SolverPermission::DepartmentRunLaunch, Target::of(modality: $modalityId, unit: $departmentId));
    }

    public function launchForCareer(User $user, string $modalityId, string $careerId): bool
    {
        return $this->actor($user)->allows(SolverPermission::CareerRunLaunch, Target::of(modality: $modalityId, career: $careerId));
    }

    public function cancel(User $user, SolverRun $run): bool
    {
        return $run->created_by === $user->getKey() || $this->canLaunchScopeOf($user, $run);
    }

    public function discard(User $user, SolverRun $run): bool
    {
        return $this->cancel($user, $run);
    }

    public function apply(User $user, SolverRun $run): bool
    {
        return $run->scope_kind === RunScope::Department
            && $run->status === SolverRunStatus::Done
            && $this->actor($user)->allows(SolverPermission::DepartmentRunApply, Target::of(modality: $run->modality_id, unit: $run->department_id));
    }

    private function canLaunchScopeOf(User $user, SolverRun $run): bool
    {
        return $run->scope_kind === RunScope::Department
            ? $this->launchForDepartment($user, $run->modality_id, $run->department_id)
            : $this->launchForCareer($user, $run->modality_id, $run->career_id);
    }
}
