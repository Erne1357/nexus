<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

/**
 * La URL de la foto de una persona (CO-04, CO-S7): una ruta `core.staff-photo` (o `core.staff-photo-mini`, la
 * miniatura de 150×200 para avatares y renglones de tabla) firmada que vence a las 2 a 3 horas. Solo la
 * arma una pantalla que ya decidió que quien mira puede ver a esa persona (la banda, la ficha, la ficha del docente, el
 * panel de la unidad, la pantalla de Comunicación). La versión va en la ruta: una foto nueva da otra URL. El
 * vencimiento se redondea (stableExpiry): dentro de la misma hora la URL no cambia, así que el navegador reutiliza la
 * foto de su caché privada en cada render.
 */
final class StaffPhotoUrl
{
    public const int TTL_SECONDS = 7200;

    /**
     * El vencimiento de una URL firmada de foto: el inicio de la hora actual más 3 horas, así que la URL es la misma
     * toda la hora y siempre vale al menos 2 horas.
     */
    public static function stableExpiry(): CarbonInterface
    {
        return now()->startOfHour()->addSeconds(self::TTL_SECONDS + 3600);
    }

    /**
     * La URL firmada de la foto vigente de `$user` (la miniatura con `$mini`), o null si no tiene.
     */
    public static function for(User $user, bool $mini = false): ?string
    {
        $version = app(StaffPhotos::class)->version($user);

        if ($version === null) {
            return null;
        }

        return URL::temporarySignedRoute($mini ? 'core.staff-photo-mini' : 'core.staff-photo', self::stableExpiry(), ['user' => $user->getKey(), 'version' => $version]);
    }
}
