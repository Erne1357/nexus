<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Events\UserReactivated;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\UserAccountRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Reactivar» una cuenta dada de baja (CF-06, confirmado el 14 de noviembre de 2026): vuelve activa y sin acceso, y sus
 * puestos no regresan solos (se nombran otra vez en la ficha). Si conservaba una contraseña (una cuenta que se
 * desactivó por otro camino), la pierde: entra con la invitación nueva. La invitación automática a su correo institucional
 * llega con 1.22-C2 (CF-07); mientras, queda «Sin acceso». No reactiva a un alumno (CF-S4), cuyo acceso sigue su estatus.
 *
 * Quita la marca de la baja del panel (`deactivated_at`, `deactivated_by`): es el único camino que reactiva una cuenta
 * dada de baja ahí (CF-09). Bloquea al usuario, deja bitácora sobre él (sin contraseña) y avisa con UserReactivated
 * después del commit. Quién puede lo decide UserPolicy.
 */
final class ReactivateUser
{
    public function __construct(private readonly UserAccountRules $rules) {}

    /**
     * @throws ValidationException sobre `user`
     */
    public function handle(User $user, User $actor): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (($blocker = $this->rules->reactivationBlocker($locked)) !== null) {
                throw ValidationException::withMessages(['user' => $blocker]);
            }

            $access = UserAccess::of($locked);
            $old = ['is_active' => false];
            $new = ['is_active' => true];

            if ($locked->deactivated_at !== null) {
                [$old['deactivated_at'], $new['deactivated_at']] = [$locked->deactivated_at->toIso8601String(), null];
            }

            if ($access !== UserAccess::None) {
                [$old['access'], $new['access']] = [$access->value, UserAccess::None->value];
                $locked->forceFill(['password' => null, 'password_origin' => null, 'access_invited_at' => null]);
                $locked->setRememberToken(Str::random(60));
            }

            $locked->forceFill(['is_active' => true, 'deactivated_at' => null, 'deactivated_by' => null])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('reactivated')
                ->withChanges(['attributes' => $new, 'old' => $old])
                ->log("Reactivó la cuenta de {$locked->username}, sin acceso");

            UserReactivated::dispatch($locked);

            $user->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }
}
