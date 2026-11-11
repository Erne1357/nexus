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
 * - Propone (sale de borrador) y pasa a asignación el jefe de departamento de ese campus y departamento (supuesto por
 *   confirmar).
 * - Cierra el jefe de departamento; Subdirección también, cualquier carga, sin ser paso obligatorio (confirmado el 10
 *   de noviembre de 2026).
 * - Reabre y da el visto bueno la Subdirección (tareas 4.1 y 4.2).
 *
 * - Publica la Subdirección, o el jefe de departamento si el campus no exige visto bueno (tarea 4.3).
 *
 * El admin pasa por la concesión implícita del registro.
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

    /**
     * Publicar: Subdirección, o el jefe del departamento si el campus no exige visto bueno (reglas-de-negocio.md,
     * tabla de permisos). Con visto bueno, la carga aprobada solo la publica quien tiene `LoadPublishApproved`.
     */
    public function publish(User $user, AcademicLoad $load): bool
    {
        return $this->can($user, $load->campus->profile->requiresSubdireccionApproval
            ? AcademicLoadPermission::LoadPublishApproved
            : AcademicLoadPermission::LoadPublish, $load);
    }

    private function can(User $user, AcademicLoadPermission $permission, AcademicLoad $load): bool
    {
        return $this->actor($user)->allows($permission, Target::of(campus: $load->campus_id, unit: $load->department_id));
    }
}
