<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Invita a un docente a activar su acceso (AC-07): manda a su correo institucional la invitación de Core
 * (SendAccessInvitation). Solo a un docente activo y sin borrar, con usuario sin contraseña y con correo institucional
 * (sin él no se habilita, confirmado el 11 de noviembre de 2026); si no, una ValidationException sobre `access` con el
 * motivo, sin escribir. Quién puede lo decide TeacherPolicy (`inviteAccess`) o quien la llama.
 */
final class InviteTeacherAccess
{
    public function __construct(private readonly SendAccessInvitation $sendAccessInvitation) {}

    /**
     * @throws ValidationException
     * @throws RuntimeException si falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(Teacher $teacher, ?User $actor = null): User
    {
        $user = $teacher->user_id === null ? null : $teacher->user()->first();

        $reason = match (true) {
            ! $teacher->is_active || $teacher->trashed() => 'El docente está inactivo.',
            $user === null => 'El docente no tiene usuario.',
            $user->password !== null => 'Ya tiene acceso.',
            $teacher->institutional_email === null => 'Falta su correo institucional.',
            default => null,
        };

        if ($reason !== null) {
            throw ValidationException::withMessages(['access' => $reason]);
        }

        return $this->sendAccessInvitation->handle($user, $teacher->institutional_email, $actor);
    }
}
