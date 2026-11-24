<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Actions\InviteUser;
use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\InvitationRelease;
use App\Modules\Faculty\Queries\SelectionQueueMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 *
 * `byAdmin()` es la invitación del admin desde la ficha (CF-07, confirmado el 14 de noviembre de 2026): la de Core
 * (InviteUser) al correo institucional del docente, con el mismo bloqueo, aunque su usuario tenga otro puesto y aunque
 * su departamento todavía no valide el catálogo de docentes. Reemplaza a «Dar acceso» con la contraseña por defecto.
 */
final class InviteTeacherAccess
{
    public const string NOT_INVITED = 'Sale cuando su departamento valida el catálogo de docentes.';

    public const string HAS_ACCESS = 'Ya tiene acceso.';

    public const string NO_EMAIL = 'Sin correo: no tiene correo institucional ni personal.';

    public const string NO_INSTITUTIONAL = 'Falta su correo institucional: captúralo en «Editar datos».';

    public function __construct(
        private readonly SendAccessInvitation $sendAccessInvitation,
        private readonly InvitationRelease $releases,
        private readonly InviteUser $inviteUser,
        private readonly SelectionQueueMembership $selectionQueues,
    ) {}

    /**
     * @throws ValidationException
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
     * La invitación del admin (CF-07): solo a un docente activo y sin borrar, con usuario y correo institucional; lo
     * demás (usuario activo, sin contraseña, no alumno) lo revisa InviteUser. Devuelve el correo al que la mandó.
     *
     * @throws ValidationException sobre `access`, o la de InviteUser
     */
    public function byAdmin(Teacher $teacher, User $actor): string
    {
        return DB::transaction(function () use ($teacher, $actor): string {
            $locked = Teacher::withTrashed()->lockForUpdate()->findOrFail($teacher->getKey());
            $user = $locked->user_id === null ? null : $locked->user()->first();

            $reason = match (true) {
                ! $locked->is_active || $locked->trashed() => 'El docente está inactivo.',
                $user === null => 'El docente no tiene usuario.',
                $locked->institutional_email === null => 'Falta su correo institucional.',
                default => null,
            };

            if ($reason !== null) {
                throw ValidationException::withMessages(['access' => $reason]);
            }

            return $this->inviteUser->handle($user, $actor, $locked->institutional_email);
        });
    }

    /**
     * SEL-13 (confirmado el 24 de noviembre de 2026: «Lo ideal sería que el Jefe valide el catálogo, pero si no lo hace,
     * sí invitación personal»): la invitación al publicar una orden de selección, a un docente de la cola sin acceso. Si
     * un departamento suyo ya validó su catálogo de docentes (InvitationRelease, AC-09) va a su correo institucional,
     * como siempre, y si no lo tiene, al personal; si no lo validó, a su correo personal (`teachers.email`) y, si no lo
     * tiene, al institucional. Es la automática de SendAccessInvitation (a quien tiene otro puesto le da acceso el
     * admin) y no manda otra a quien tiene una vigente. Devuelve el correo al que la mandó, o null si ya tenía una
     * vigente.
     *
     * @throws ValidationException sobre `access` (inactivo, sin usuario, ya tiene acceso, sin correo) o la de
     *                             SendAccessInvitation
     */
    public function forSelection(Teacher $teacher, ?User $actor = null): ?string
    {
        return $this->inviteForSelection($teacher, $actor, unlessInvited: true);
    }

    /**
     * SEL-13 (confirmado el 24 de noviembre de 2026: «El jefe o admin le reenvía la invitación»): «Reenviar al correo
     * personal» del jefe de su departamento o del admin, para el docente sin correo institucional que está en la cola de
     * una orden de selección no cancelada (SelectionQueueMembership). Es la de forSelection con su enlace de siempre, y
     * una nueva deja sin efecto la anterior. Fuera de una selección, o con correo institucional, se rechaza con el motivo
     * y va la de siempre. Quién puede lo decide TeacherPolicy (`inviteAccess`). Devuelve el correo al que la mandó.
     *
     * @throws ValidationException sobre `access`, o la de SendAccessInvitation
     */
    public function resendToPersonal(Teacher $teacher, User $actor): string
    {
        if (self::usable($teacher->institutional_email) !== null) {
            throw ValidationException::withMessages(['access' => 'Tiene correo institucional: usa «Reenviar invitación».']);
        }

        if (! $this->selectionQueues->includes($teacher)) {
            throw ValidationException::withMessages(['access' => self::NO_INSTITUTIONAL]);
        }

        return (string) $this->inviteForSelection($teacher, $actor, unlessInvited: false);
    }

    /**
     * @throws ValidationException
     */
    private function inviteForSelection(Teacher $teacher, ?User $actor, bool $unlessInvited): ?string
    {
        return DB::transaction(function () use ($teacher, $actor, $unlessInvited): ?string {
            $locked = Teacher::withTrashed()->lockForUpdate()->findOrFail($teacher->getKey());
            $user = $locked->user_id === null ? null : $locked->user()->first();
            $institutional = self::usable($locked->institutional_email);
            $personal = self::usable($locked->email);
            $email = $this->releases->isReleased($locked) ? ($institutional ?? $personal) : ($personal ?? $institutional);

            $reason = match (true) {
                ! $locked->is_active || $locked->trashed() => 'El docente está inactivo.',
                $user === null => 'El docente no tiene usuario.',
                $user->password !== null => self::HAS_ACCESS,
                $email === null => self::NO_EMAIL,
                default => null,
            };

            if ($reason !== null) {
                throw ValidationException::withMessages(['access' => $reason]);
            }

            return $this->sendAccessInvitation->handle($user, $email, $actor, $unlessInvited) ? $email : null;
        });
    }

    /**
     * Un correo con forma de correo, o null.
     */
    private static function usable(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * «Reenviar invitación» de la ficha: a quien ya se invitó, o la primera si un departamento suyo ya validó su catálogo
     * de docentes (InvitationRelease); antes, la primera sale con esa validación (AC-07, supuesto por confirmar).
     *
     * @throws ValidationException
     */
    public function resend(Teacher $teacher, ?User $actor = null): bool
    {
        if ($teacher->user_id !== null && $teacher->user()->value('access_invited_at') === null && ! $this->releases->isReleased($teacher)) {
            throw ValidationException::withMessages(['access' => self::NOT_INVITED]);
        }

        return $this->handle($teacher, $actor);
    }
}
