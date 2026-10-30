<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Devuelve a un usuario a la contraseña por defecto y lo obliga a cambiarla. El hash nuevo cierra
 * las demás sesiones (AuthenticateSession) y se rota el remember token. Solo la usa el
 * administrador. La bitácora registra el reinicio, nunca la contraseña.
 */
final class ResetUserPassword
{
    public function __construct(private readonly DefaultPassword $defaultPassword) {}

    public function handle(User $user): void
    {
        $password = $this->defaultPassword->value();

        DB::transaction(function () use ($user, $password): void {
            $user = User::lockForUpdate()->findOrFail($user->getKey());

            $user->forceFill(['password' => $password, 'must_change_password' => true]);
            $user->setRememberToken(Str::random(60));
            $user->save();

            activity()
                ->performedOn($user)
                ->causedBy(auth()->user())
                ->event('password-reset')
                ->log("Reinició la contraseña de {$user->username}");
        });
    }
}
