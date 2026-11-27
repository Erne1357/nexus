<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Support\AccessInvitationLink;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Invita a un usuario sin acceso a activarlo (AC-07, confirmado el 11 de noviembre de 2026): su correo pasa a ser
 * `$email` (en minúsculas), sin verificar, y `access_invited_at`, ahora; luego le manda a ese correo AccessInvitation
 * con su usuario y el enlace (AccessInvitationLink), que vence a los 7 días; la contraseña la elige él al activar
 * (AC-08, confirmado el 14 de noviembre de 2026). Una invitación
 * nueva deja sin efecto la anterior: su hora siempre es posterior, aunque caigan en el mismo segundo. Quién puede lo
 * deciden las Actions y pantallas que la llaman.
 *
 * Solo a un usuario activo y sin contraseña. Las invitaciones automáticas (la validación del catálogo de docentes,
 * AC-09, y la captura del correo institucional, AC-07) no van a quien tiene un puesto distinto de docente vigente o por
 * empezar, así que nadie puede llevar a otro buzón el acceso de un jefe. La del admin (`$byAdmin`, InviteUser, CF-07,
 * confirmado el 14 de noviembre de 2026) sí, porque él revisa el correo al mandarla: deja `access_invited_by` con
 * `$actor`, y AccessInvitationLink la abre aunque tenga un puesto; cualquier otra la deja nula. Si otro usuario ya tiene
 * ese correo (sin importar mayúsculas), no escribe. En una transacción bloquea al usuario y deja en la bitácora el
 * correo (y `by_admin` en la del admin); el correo sale de la cola después del commit.
 * Con `$unlessInvited`, si el usuario ya tiene una invitación vigente no hace nada: se revisa con el usuario
 * bloqueado, así dos corridas a la vez no le mandan dos. Devuelve si la mandó.
 *
 * CE-01: la invitación sale solo a un correo institucional; `$personalMailRule` es la excepción declarada que deja
 * mandarla a uno personal (SEL-13, la de la cola de una orden de selección y su reenvío, InviteTeacherAccess). Sin ella,
 * a un correo personal la guardia no la deja salir (GuardMailRecipient).
 */
final class SendAccessInvitation
{
    public const string STAFF = 'Tiene un puesto además de docente: le da acceso el administrador.';

    public function __construct(
        private readonly AccessInvitationLink $link,
        private readonly RoleAssignmentRules $rules,
    ) {}

    /**
     * @throws ValidationException si el usuario ya tiene acceso, está inactivo, tiene otro puesto (salvo `$byAdmin`) o el
     *                             correo es de otro usuario
     */
    public function handle(User $user, string $email, ?User $actor = null, bool $unlessInvited = false, bool $byAdmin = false, ?string $personalMailRule = null): bool
    {
        $email = mb_strtolower(trim($email));

        if ($byAdmin && $actor === null) {
            throw new LogicException('La invitación del admin necesita a quien la manda.');
        }

        return DB::transaction(function () use ($user, $email, $actor, $unlessInvited, $byAdmin, $personalMailRule): bool {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($unlessInvited && $user->access_invited_at !== null && $this->link->expiresAt($user)->isFuture()) {
                return false;
            }

            if (! $user->is_active || $user->password !== null) {
                throw ValidationException::withMessages(['user' => $user->is_active ? 'Ya tiene acceso.' : 'Su usuario está inactivo.']);
            }

            if (! $byAdmin && $this->rules->holdsStaffRole($user->getKey())) {
                throw ValidationException::withMessages(['user' => self::STAFF]);
            }

            if (User::query()->whereRaw('lower(email) = ?', [$email])->whereKeyNot($user->getKey())->exists()) {
                throw $this->emailTaken($email);
            }

            $invitedAt = now()->startOfSecond();

            if ($user->access_invited_at !== null && $invitedAt->lte($user->access_invited_at)) {
                $invitedAt = $user->access_invited_at->addSecond();
            }

            try {
                $user->forceFill([
                    'email' => $email,
                    'email_verified_at' => null,
                    'access_invited_at' => $invitedAt,
                    'access_invited_by' => $byAdmin ? $actor->getKey() : null,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->emailTaken($email);
            }

            $user->refresh();

            activity()
                ->performedOn($user)
                ->causedBy($actor)
                ->event('access-invited')
                ->withProperties(['email' => $email, ...($byAdmin ? ['by_admin' => true] : [])])
                ->log("Invitó a {$user->username} a Nexus en {$email}");

            Notification::route('mail', $email)->notify(new AccessInvitation(
                $user->username,
                $user->name,
                $this->link->url($user),
                $this->link->expiresAt($user),
                $personalMailRule,
            ));

            return true;
        });
    }

    private function emailTaken(string $email): ValidationException
    {
        return ValidationException::withMessages(['email' => "Otro usuario ya tiene el correo {$email}."]);
    }
}
