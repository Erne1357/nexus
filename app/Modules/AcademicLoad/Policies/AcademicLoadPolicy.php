<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve una carga y quién la pasa de un estado a otro, según docs/dominio/reglas-de-negocio.md, "Aprobaciones y
 * permisos":
 *
 * - Ven la carga la subdirección, el jefe de departamento y el jefe de proyecto docente, dentro de su
 *   campus y su departamento.
 * - Propone (sale de borrador), pasa a asignación y cierra el jefe de departamento de ese campus y departamento
 *   (supuesto por confirmar).
 * - Reabre y da el visto bueno la Subdirección (tareas 4.1 y 4.2).
 *
 * El admin pasa por la concesión implícita del registro. Publicar llega con la tarea 4.3.
 */
final class AcademicLoadPolicy
{
    use AuthorizesActor;

    public function view(User $user, AcademicLoad $load): bool
    {
        return $this->can($user, AcademicLoadPermission::LoadView, $load);
    }

    public function propose(User $user, AcademicLoad $load): bool
    {
        return $this->can($user, AcademicLoadPermission::LoadPropose, $load);
    }

    public function startStaffing(User $user, AcademicLoad $load): bool
    {
        return $this->can($user, AcademicLoadPermission::LoadStartStaffing, $load);
    }

    public function close(User $user, AcademicLoad $load): bool
    {
        return $this->can($user, AcademicLoadPermission::LoadClose, $load);
    }

    public function reopen(User $user, AcademicLoad $load): bool
    {
        return $this->can($user, AcademicLoadPermission::LoadReopen, $load);
    }

    public function approve(User $user, AcademicLoad $load): bool
    {
        return $this->can($user, AcademicLoadPermission::LoadApprove, $load);
    }

    private function can(User $user, AcademicLoadPermission $permission, AcademicLoad $load): bool
    {
        return $this->actor($user)->allows($permission, Target::of(campus: $load->campus_id, unit: $load->department_id));
    }
}
