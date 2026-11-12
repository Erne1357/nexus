<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Support\AccessInvitationLink;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Invita a un usuario sin acceso a activarlo (AC-07, confirmado el 11 de noviembre de 2026): su correo pasa a ser
 * `$email` (en minúsculas), sin verificar, y `access_invited_at`, ahora; luego le manda a ese correo AccessInvitation
 * con su usuario, la contraseña por defecto y el enlace (AccessInvitationLink), que vence a los 7 días. Una invitación
 * nueva deja sin efecto la anterior: su hora siempre es posterior, aunque caigan en el mismo segundo. Quién puede lo
 * deciden las Actions y pantallas que la llaman.
 *
 * Solo a un usuario activo, sin contraseña y sin un puesto distinto de docente vigente o por empezar: a los jefes y
 * demás personal con puesto los habilita el admin a mano (AC-03), así que nadie puede llevar su invitación a otro
 * buzón. Si otro usuario ya tiene ese correo (sin importar mayúsculas), no escribe. Sin NEXUS_DEFAULT_PASSWORD falla antes de escribir, porque el correo la lleva. En una transacción bloquea
 * al usuario y deja en la bitácora el correo, nunca la contraseña; el correo sale de la cola después del commit.
 */
final class SendAccessInvitation
{
    public const string STAFF = 'Tiene un puesto además de docente: le da acceso el administrador.';

    public function __construct(
        private readonly DefaultPassword $defaultPassword,
        private readonly AccessInvitationLink $link,
        private readonly RoleAssignmentRules $rules,
    ) {}

    /**
     * @throws ValidationException si el usuario ya tiene acceso, está inactivo o el correo es de otro usuario
     * @throws RuntimeException si falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(User $user, string $email, ?User $actor = null): User
    {
        $this->defaultPassword->value();
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($user, $email, $actor): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! $user->is_active || $user->password !== null) {
                throw ValidationException::withMessages(['user' => $user->is_active ? 'Ya tiene acceso.' : 'Su usuario está inactivo.']);
            }

            if ($this->rules->holdsStaffRole($user->getKey())) {
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
                $user->forceFill(['email' => $email, 'email_verified_at' => null, 'access_invited_at' => $invitedAt])->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->emailTaken($email);
            }

            $user->refresh();

            activity()
                ->performedOn($user)
                ->causedBy($actor)
                ->event('access-invited')
                ->withProperties(['email' => $email])
                ->log("Invitó a {$user->username} a Nexus en {$email}");

            Notification::route('mail', $email)->notify(new AccessInvitation(
                $user->username,
                $user->name,
                $this->link->url($user),
                $this->link->expiresAt($user),
            ));

            return $user;
        });
    }

    private function emailTaken(string $email): ValidationException
    {
        return ValidationException::withMessages(['email' => "Otro usuario ya tiene el correo {$email}."]);
    }
}
