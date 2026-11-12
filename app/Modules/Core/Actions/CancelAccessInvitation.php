<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deja sin efecto la invitación de acceso de un usuario que todavía no tiene contraseña: borra `access_invited_at`, así
 * que su enlace deja de servir (AC-07: cambiar el correo institucional invalida el enlace anterior, supuesto por
 * confirmar), y el correo sin verificar que le puso la invitación. Sin invitación o con contraseña no escribe nada.
 * Deja bitácora sobre el usuario.
 */
final class CancelAccessInvitation
{
    public function handle(User $user, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($user->password !== null || $user->access_invited_at === null) {
                return;
            }

            $old = ['access_invited_at' => $user->access_invited_at->toIso8601String()];
            $new = ['access_invited_at' => null];

            if ($user->email_verified_at === null && $user->email !== null) {
                [$old['email'], $new['email']] = [$user->email, null];
            }

            $user->forceFill($new)->save();

            activity()
                ->performedOn($user)
                ->causedBy($actor)
                ->event('access-invitation-cancelled')
                ->withChanges(['attributes' => $new, 'old' => $old])
                ->log("Dejó sin efecto la invitación de acceso de {$user->username}");
        });
    }
}
