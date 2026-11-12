<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Pone la contraseña nueva de quien llegó con el enlace de su correo (AC-18). Como UpdateUserPassword, valida su
 * entrada: una contraseña nueva confirmada, conforme a Password::defaults() y que no sea la por defecto. Después, con
 * el usuario bloqueado, revisa que el enlace siga sirviendo (PasswordResetTokens, AC-17) y, si sirve, pone la
 * contraseña, quita el cambio obligatorio, da su correo por confirmado si no lo estaba (el enlace llegó a él), rota el
 * remember token y borra el token, así que el enlace no vuelve a servir. Las demás sesiones abiertas las cierra
 * AuthenticateSession en su siguiente petición, porque cambió el hash. No inicia sesión. Deja bitácora a su nombre,
 * sin la contraseña ni el token. Devuelve el usuario, o null si el enlace no sirve, sin decir por qué.
 */
final class ResetForgottenPassword
{
    public function __construct(
        private readonly PasswordResetTokens $tokens,
        private readonly DefaultPassword $defaultPassword,
    ) {}

    /**
     * @param  array<string, mixed>  $input  `password` y `password_confirmation`
     *
     * @throws ValidationException
     */
    public function handle(string $username, #[SensitiveParameter] string $token, #[SensitiveParameter] array $input): ?User
    {
        $validated = Validator::make($input, [
            'password' => ['required', 'string', Password::defaults(), 'confirmed', $this->defaultPassword->rejectionRule()],
        ])->validate();

        return DB::transaction(function () use ($username, $token, $validated): ?User {
            $user = User::query()->where('username', $username)->lockForUpdate()->first();

            if (! $this->tokens->opens($user, $token)) {
                return null;
            }

            $user->forceFill([
                'password' => $validated['password'],
                'must_change_password' => false,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
            $user->setRememberToken(Str::random(60));
            $user->save();

            $this->tokens->delete($user);

            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->event('password-reset-with-link')
                ->log("{$user->username} restableció su contraseña con el enlace de su correo");

            return $user;
        });
    }
}
