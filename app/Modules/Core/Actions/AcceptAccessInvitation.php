<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Support\AccessInvitationLink;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Activa el acceso de quien abrió su invitación y eligió su contraseña (AC-08, confirmado el 14 de noviembre de 2026:
 * el enlace basta y no se le pide ninguna contraseña actual). La firma del enlace la revisa la ruta; aquí, con el
 * usuario bloqueado, revisa primero que exista, siga activo y sin contraseña, y que la huella sea la de su invitación
 * vigente (AccessInvitationLink): un enlace usado, reenviado, de un correo que cambió o vencido no sirve, y entonces
 * devuelve null sin mirar la entrada, así que todo enlace que no sirve responde igual. Si sirve, valida la contraseña
 * como el restablecimiento por correo (DefaultPassword::newPasswordRules(): confirmada, conforme a
 * Password::defaults() y que no sea la por defecto) y la pone como **propia** (`password_origin = user`, AC-12), sin
 * cambio obligatorio, con su correo verificado, porque el enlace llegó a él (decisión 3, confirmada el 11 de noviembre
 * de 2026), y con el remember token nuevo. Como el usuario no tenía contraseña, el enlace ya no vuelve a servir. Deja
 * bitácora a su nombre, sin la contraseña. Devuelve el usuario, o null si el enlace no sirve, sin decir por qué.
 */
final class AcceptAccessInvitation
{
    public function __construct(
        private readonly DefaultPassword $defaultPassword,
        private readonly AccessInvitationLink $link,
    ) {}

    /**
     * @param  array<string, mixed>  $input  `password` y `password_confirmation`
     *
     * @throws ValidationException si el enlace sirve pero la contraseña no
     */
    public function handle(string $userId, string $hash, #[SensitiveParameter] array $input): ?User
    {
        return DB::transaction(function () use ($userId, $hash, $input): ?User {
            $user = KeyLookup::find(User::query()->lockForUpdate(), $userId);

            if (! $user instanceof User || ! $this->link->opens($user, $hash)) {
                return null;
            }

            $validated = Validator::make($input, ['password' => $this->defaultPassword->newPasswordRules()])->validate();

            $user->forceFill([
                'password' => $validated['password'],
                'password_origin' => PasswordOrigin::User,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ]);
            $user->setRememberToken(Str::random(60));
            $user->save();

            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->event('access-accepted')
                ->log("{$user->username} activó su acceso con la invitación");

            return $user;
        });
    }
}
