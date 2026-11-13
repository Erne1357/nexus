<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deja a un usuario sin acceso (AC-01): quita su contraseña, su origen y su invitación vigente, así que no entra hasta
 * que reciba una invitación nueva a su correo institucional (AC-07, AC-09), como un docente nuevo. La usa la
 * reactivación de un docente por el jefe al agregarlo a su departamento (CV-S9, confirmado el 12 de noviembre de 2026):
 * su contraseña anterior no vuelve. Rota el remember token; la sesión que tuviera abierta se cierra en su siguiente
 * petición (EnsureUserIsActive). Sin contraseña ni invitación no escribe nada. La bitácora nunca lleva la contraseña.
 * Devuelve si cambió algo.
 */
final class RevokeUserAccess
{
    public function handle(User $user, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($user, $actor): bool {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($user->password === null && $user->access_invited_at === null) {
                return false;
            }

            $user->forceFill(['password' => null, 'password_origin' => null, 'must_change_password' => false, 'access_invited_at' => null]);
            $user->setRememberToken(Str::random(60));
            $user->save();

            activity()
                ->performedOn($user)
                ->causedBy($actor)
                ->event('access-revoked')
                ->log("Quitó el acceso de {$user->username}: lo recupera con una invitación nueva");

            return true;
        });
    }
}
