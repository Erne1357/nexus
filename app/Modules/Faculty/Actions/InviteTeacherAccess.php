<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Invita a un docente a activar su acceso (AC-07): manda a su correo institucional la invitación de Core
 * (SendAccessInvitation). Solo a un docente activo y sin borrar, con usuario sin contraseña y con correo institucional
 * (sin él no se habilita, confirmado el 11 de noviembre de 2026), y sin otro puesto (SendAccessInvitation: a los jefes
 * los habilita el admin); si no, una ValidationException sobre `access` con el motivo, sin escribir. Con
 * `$unlessInvited`, a quien ya tiene una invitación vigente no se le manda otra. Devuelve si la mandó. Quién puede lo
 * decide TeacherPolicy (`inviteAccess`) o quien la llama.
 *
 * En una transacción vuelve a leer al docente con bloqueo y después SendAccessInvitation bloquea a su usuario, en el
 * mismo orden que UpdateTeacher: la invitación va al correo que el docente tiene en la base, aunque `$teacher` llegue
 * viejo o alguien lo esté cambiando.
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
        return DB::transaction(function () use ($teacher, $actor, $unlessInvited): bool {
            $locked = Teacher::withTrashed()->lockForUpdate()->findOrFail($teacher->getKey());
            $user = $locked->user_id === null ? null : $locked->user()->first();

            $reason = match (true) {
                ! $locked->is_active || $locked->trashed() => 'El docente está inactivo.',
                $user === null => 'El docente no tiene usuario.',
                $user->password !== null => 'Ya tiene acceso.',
                $locked->institutional_email === null => 'Falta su correo institucional.',
                default => null,
            };

            if ($reason !== null) {
                throw ValidationException::withMessages(['access' => $reason]);
            }

            return $this->sendAccessInvitation->handle($user, $locked->institutional_email, $actor, $unlessInvited);
        });
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
