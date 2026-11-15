<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Events\UserPasswordReset;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Devuelve a un usuario a la contraseña por defecto y lo obliga a cambiarla. El hash nuevo cierra
 * las demás sesiones (AuthenticateSession) y se rota el remember token. A un usuario sin contraseña
 * es **dar acceso** (AC-03): la habilitación manual, que la bitácora registra como `access-granted`
 * en lugar de `password-reset`. Solo la usa el administrador, que se pasa como `$actor`. La bitácora
 * nunca lleva la contraseña. Devuelve si fue dar acceso, decidido con el renglón bloqueado. A un usuario inactivo (o
 * dado de baja en el panel, CF-05) no le da acceso ni le reinicia la contraseña: lo revisa con el renglón bloqueado y lanza
 * un RuntimeException, que las pantallas que la llaman ya muestran en un toast.
 */
final class ResetUserPassword
{
    public function __construct(private readonly DefaultPassword $defaultPassword) {}

    /**
     * @throws RuntimeException si el usuario está inactivo o falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(User $user, ?User $actor = null): bool
    {
        $password = $this->defaultPassword->value();

        return DB::transaction(function () use ($user, $actor, $password): bool {
            $user = User::lockForUpdate()->findOrFail($user->getKey());

            if (! $user->is_active || $user->deactivated_at !== null) {
                throw new RuntimeException('Su usuario está inactivo.');
            }

            $granting = $user->password === null;

            $user->forceFill(['password' => $password, 'password_origin' => PasswordOrigin::Default, 'must_change_password' => true]);
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

            return $granting;
        });
    }
}
