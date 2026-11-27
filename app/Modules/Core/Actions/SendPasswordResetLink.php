<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PasswordResetLink;
use App\Modules\Core\Rules\PasswordResetRules;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Manda al correo institucional de `$username` el enlace para restablecer su contraseña (AC-16), si las reglas lo
 * dejan (PasswordResetRules) y no se le mandó otro hace menos de un minuto (AC-17); si no, no hace nada ni dice por
 * qué. Corre en la cola (SendPasswordResetLinkJob), así que la pantalla responde igual, exista o no el usuario.
 *
 * Crea el token y deja la bitácora (a qué correo va, nunca el token) con el usuario bloqueado, para que dos peticiones
 * a la vez no le manden dos, y manda el correo después del commit: si el correo sale, ya no queda nada que pueda fallar
 * y hacer que el reintento mande otro enlace que deje sin efecto al primero. Si el correo falla, borra el token y deja
 * pasar el error, para que el reintento mande uno nuevo. Devuelve si lo mandó.
 */
final class SendPasswordResetLink
{
    public function __construct(
        private readonly PasswordResetRules $rules,
        private readonly PasswordResetTokens $tokens,
    ) {}

    public function handle(string $username): bool
    {
        $issued = DB::transaction(function () use ($username): ?array {
            $user = User::query()->where('username', $username)->lockForUpdate()->first();

            if (! $user instanceof User || ! $this->rules->allows($user)) {
                return null;
            }

            $token = $this->tokens->create($user);

            if ($token === null) {
                return null;
            }

            activity()
                ->performedOn($user)
                ->event('password-reset-link-issued')
                ->withProperties(['email' => $user->email])
                ->log("Se generó un enlace para restablecer la contraseña de {$user->username}, que va a {$user->email}");

            return [$user, $token];
        });

        if ($issued === null) {
            return false;
        }

        [$user, $token] = $issued;

        try {
            $user->notify(new PasswordResetLink($user->username, $user->name, $this->tokens->url($user, $token), $this->tokens->minutes()));
        } catch (Throwable $exception) {
            $this->tokens->delete($user);

            throw $exception;
        }

        return true;
    }
}
