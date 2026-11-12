<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Support\AccessInvitationLink;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Activa el acceso de quien abrió su invitación y pulsó el botón (AC-08). La firma del enlace la revisa la ruta; aquí,
 * con el usuario bloqueado, que exista, siga activo y sin contraseña, y que la huella sea la de su invitación vigente
 * (AccessInvitationLink): un enlace usado, reenviado, de un correo que cambió o vencido no sirve. Si sirve, le pone la
 * contraseña por defecto con cambio obligatorio y da su correo por verificado, porque el enlace llegó a él (decisión 3,
 * confirmada el 11 de noviembre de 2026). Deja bitácora a su nombre, sin la contraseña. Devuelve el usuario, o null si
 * el enlace no sirve, sin decir por qué.
 */
final class AcceptAccessInvitation
{
    public function __construct(
        private readonly DefaultPassword $defaultPassword,
        private readonly AccessInvitationLink $link,
    ) {}

    /**
     * @throws RuntimeException si falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(string $userId, string $hash): ?User
    {
        $password = $this->defaultPassword->value();

        return DB::transaction(function () use ($userId, $hash, $password): ?User {
            $user = KeyLookup::find(User::query()->lockForUpdate(), $userId);

            if (! $user instanceof User || ! $this->link->opens($user, $hash)) {
                return null;
            }

            $user->forceFill(['password' => $password, 'password_origin' => PasswordOrigin::Default, 'must_change_password' => true, 'email_verified_at' => now()]);
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
