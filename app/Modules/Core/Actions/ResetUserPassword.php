<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\UserPasswordReset;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Devuelve a un usuario a la contraseña por defecto y lo obliga a cambiarla. El hash nuevo cierra
 * las demás sesiones (AuthenticateSession) y se rota el remember token. A un usuario sin contraseña
 * es **dar acceso** (AC-03): la habilitación manual, que la bitácora registra como `access-granted`
 * en lugar de `password-reset`. Solo la usa el administrador, que se pasa como `$actor`. La bitácora
 * nunca lleva la contraseña.
 */
final class ResetUserPassword
{
    public function __construct(private readonly DefaultPassword $defaultPassword) {}

    public function handle(User $user, ?User $actor = null): void
    {
        $password = $this->defaultPassword->value();

        DB::transaction(function () use ($user, $actor, $password): void {
            $user = User::lockForUpdate()->findOrFail($user->getKey());
            $granting = $user->password === null;

            $user->forceFill(['password' => $password, 'must_change_password' => true]);
            $user->setRememberToken(Str::random(60));
            $user->save();

            activity()
                ->performedOn($user)
                ->causedBy($actor)
                ->event($granting ? 'access-granted' : 'password-reset')
                ->log($granting
                    ? "Dio acceso a {$user->username} con la contraseña por defecto"
                    : "Reinició la contraseña de {$user->username}");

            UserPasswordReset::dispatch($user, $actor);
        });
    }
}
