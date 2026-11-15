<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\UserAccountRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La invitación de acceso del admin (CF-07, confirmado el 14 de noviembre de 2026): «Enviar invitación» o «Reenviar
 * invitación» en el panel, en la ficha del docente y en «Correos y colas». Va a cualquier usuario activo, sin contraseña
 * y con correo institucional, tenga o no un puesto (jefes, Subdirección, Servicios Escolares, otro admin…), y reemplaza
 * a «Dar acceso» con la contraseña por defecto (AC-03). Va al correo del usuario o a `$email` (el correo institucional
 * del docente, desde su ficha); por qué no, lo dice UserAccountRules::invitationBlocker().
 *
 * Con el usuario bloqueado revisa esas condiciones y llama a SendAccessInvitation con `byAdmin`: la invitación recuerda
 * que la mandó `$actor` (`users.access_invited_by`), su enlace abre aunque tenga un puesto y deja sin efecto la
 * anterior. El correo sale de la cola después del commit. Quién puede lo decide UserPolicy (`invite`, el candado
 * `core.user.invite`). Devuelve el correo al que la mandó.
 */
final class InviteUser
{
    public function __construct(
        private readonly UserAccountRules $rules,
        private readonly SendAccessInvitation $sendAccessInvitation,
    ) {}

    /**
     * @throws ValidationException sobre `user`, o sobre `email` si otro usuario ya tiene ese correo
     */
    public function handle(User $user, User $actor, ?string $email = null): string
    {
        return DB::transaction(function () use ($user, $actor, $email): string {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $email = mb_strtolower(trim($email ?? (string) $locked->email));

            if (($blocker = $this->rules->invitationBlocker($locked, $email)) !== null) {
                throw ValidationException::withMessages(['user' => $blocker]);
            }

            $this->sendAccessInvitation->handle($locked, $email, $actor, byAdmin: true);

            $user->setRawAttributes($locked->refresh()->getAttributes(), sync: true);

            return $email;
        });
    }
}
