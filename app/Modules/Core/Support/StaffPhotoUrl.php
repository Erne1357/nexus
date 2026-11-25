<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * La URL de la foto de una persona (CO-04, CO-S7): una ruta `core.staff-photo` firmada que vence a las 2 horas. Solo la
 * arma una pantalla que ya decidió que quien mira puede ver a esa persona (la banda, la ficha, la ficha del docente, el
 * panel de la unidad, la pantalla de Comunicación). La versión va en la ruta: una foto nueva da otra URL.
 */
final class StaffPhotoUrl
{
    public const int TTL_SECONDS = 7200;

    /**
     * La URL firmada de la foto vigente de `$user`, o null si no tiene.
     */
    public static function for(User $user): ?string
    {
        $version = app(StaffPhotos::class)->version($user);

        if ($version === null) {
            return null;
        }

        return URL::temporarySignedRoute('core.staff-photo', now()->addSeconds(self::TTL_SECONDS), ['user' => $user->getKey(), 'version' => $version]);
    }
}
