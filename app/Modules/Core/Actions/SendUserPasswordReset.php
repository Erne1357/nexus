<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PasswordResetLink;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Restablecer contraseña» desde el panel (CF-08, confirmado el 14 de noviembre de 2026): el admin manda el enlace de
 * AC-16 al correo institucional del usuario; el admin nunca ve ni pone una contraseña. Es la base de
 * SendPasswordResetLink (las reglas de AC-16, los enlaces de PasswordResetTokens y el correo PasswordResetLink), pero
 * la dispara el admin: dice por qué no (UserAccountRules::resetBlocker(), o que ya se le mandó uno hace menos de un
 * minuto, AC-17) y la bitácora lleva quién lo pidió como causante, a qué correo va y nunca el token. El token lleva al
 * admin (`issued_by`), así que sirve aunque el correo del personal no esté confirmado, y al usarlo queda confirmado
 * (AC-18; confirmado el 14 de noviembre de 2026).
 *
 * Crea el token y deja la bitácora con el usuario bloqueado y manda el correo después del commit, en la misma petición:
 * el token no debe viajar en la cola (PasswordResetLink no es ShouldQueue). Si el correo falla, borra el token y deja
 * pasar el error, para que se pueda pedir otro; como la bitácora no se edita, deja otra entrada que dice que el correo no
 * salió. Devuelve el correo al que lo mandó.
 */
final class SendUserPasswordReset
{
    public function __construct(
        private readonly UserAccountRules $rules,
        private readonly PasswordResetTokens $tokens,
    ) {}

    /**
     * @throws ValidationException sobre `user`
     */
    public function handle(User $user, User $actor): string
    {
        [$locked, $token] = DB::transaction(function () use ($user, $actor): array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (($blocker = $this->rules->resetBlocker($locked)) !== null) {
                throw ValidationException::withMessages(['user' => $blocker]);
            }

            $token = $this->tokens->create($locked, issuedBy: $actor) ?? throw ValidationException::withMessages(['user' => UserAccountRules::RESET_RECENT]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('password-reset-link-issued')
                ->withProperties(['email' => $locked->email])
                ->log("Mandó a {$locked->username} el enlace para restablecer su contraseña, que va a {$locked->email}");

            return [$locked, $token];
        });

        try {
            $locked->notify(new PasswordResetLink($locked->username, $locked->name, $this->tokens->url($locked, $token), $this->tokens->minutes()));
        } catch (Throwable $exception) {
            $this->tokens->delete($locked);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('password-reset-link-failed')
                ->withProperties(['email' => $locked->email])
                ->log("No salió el correo con el enlace para restablecer la contraseña de {$locked->username}: el enlace se borró");

            throw $exception;
        }

        return (string) $locked->email;
    }
}
