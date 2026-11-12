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
 * (sin él no se habilita, confirmado el 11 de noviembre de 2026), y sin otro puesto (SendAccessInvitation: a los jefes
 * los habilita el admin); si no, una ValidationException sobre `access` con el motivo, sin escribir. Con
 * `$unlessInvited`, a quien ya tiene una invitación vigente no se le manda otra. Devuelve si la mandó. Quién puede lo
 * decide TeacherPolicy (`inviteAccess`) o quien la llama.
 */
final class InviteTeacherAccess
{
    public const string NOT_INVITED = 'Sale cuando su departamento valida el catálogo de docentes.';

    public function __construct(private readonly SendAccessInvitation $sendAccessInvitation) {}

    /**
     * @throws ValidationException
     * @throws RuntimeException si falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(Teacher $teacher, ?User $actor = null, bool $unlessInvited = false): bool
    {
        $user = match (true) {
            $teacher->user_id === null => null,
            $teacher->relationLoaded('user') => $teacher->user,
            default => $teacher->user()->first(),
        };

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

        return $this->sendAccessInvitation->handle($user, $teacher->institutional_email, $actor, $unlessInvited);
    }

    /**
     * «Reenviar invitación»: solo a quien ya se invitó; la primera sale cuando su departamento valida el catálogo de
     * docentes (AC-07, supuesto por confirmar).
     *
     * @throws ValidationException
     * @throws RuntimeException si falta NEXUS_DEFAULT_PASSWORD
     */
    public function resend(Teacher $teacher, ?User $actor = null): bool
    {
        if ($teacher->user_id !== null && $teacher->user()->value('access_invited_at') === null) {
            throw ValidationException::withMessages(['access' => self::NOT_INVITED]);
        }

        return $this->handle($teacher, $actor);
    }
}
