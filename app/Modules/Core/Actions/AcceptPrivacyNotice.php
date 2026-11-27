<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\PrivacyNotice;
use Illuminate\Support\Facades\DB;

/**
 * AC-22: el usuario acepta el aviso de privacidad vigente. Guarda cuándo (`privacy_notice_accepted_at`) y qué versión
 * (`privacy_notice_version`, PrivacyNotice::VERSION) y lo deja en la bitácora a su nombre, con la versión. Si ya había
 * aceptado esa misma versión, no escribe nada. La llaman la pantalla «Aviso de privacidad» (AcceptPrivacyNoticePage) y
 * el cambio de contraseña del alumno (UpdateUserPassword), que la llama dentro de su transacción.
 */
final class AcceptPrivacyNotice
{
    public function handle(User $user): void
    {
        if ($user->privacy_notice_version === PrivacyNotice::VERSION) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'privacy_notice_accepted_at' => now(),
                'privacy_notice_version' => PrivacyNotice::VERSION,
            ])->save();

            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->event('privacy-notice-accepted')
                ->withProperties(['version' => PrivacyNotice::VERSION])
                ->log('Aceptó el aviso de privacidad');
        });
    }
}
