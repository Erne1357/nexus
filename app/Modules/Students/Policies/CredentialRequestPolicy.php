<?php

namespace App\Modules\Students\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;

/**
 * Quién pide y quién atiende las solicitudes de credencial (CO-01, CO-07, CO-S2): el alumno pide y cancela solo lo suyo
 * (`students.credential_request.create`, y cancelar solo mientras esté solicitada); Comunicación y Difusión las atiende
 * (`students.credential_request.manage`, que aplica solo a esa unidad).
 */
final class CredentialRequestPolicy
{
    use AuthorizesActor;

    /**
     * El alumno ligado a `$user` pide para sí mismo.
     */
    public function create(User $user, Student $student): bool
    {
        return $student->user_id === $user->getKey() && $this->actor($user)->allowsAny(StudentsPermission::CredentialRequestCreate);
    }

    public function cancel(User $user, CredentialRequest $request): bool
    {
        return $request->status === CredentialRequestStatus::Solicitada
            && $request->student()->value('user_id') === $user->getKey()
            && $this->actor($user)->allowsAny(StudentsPermission::CredentialRequestCreate);
    }

    public function manage(User $user): bool
    {
        return $this->actor($user)->allowsAny(StudentsPermission::CredentialRequestManage);
    }
}
