<?php

namespace App\Modules\AcademicLoad\Rules;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * El límite de la consulta pública para las actualizaciones de Livewire (búsquedas y selectores), que no pasan por el
 * middleware `throttle:published-schedule` de las rutas GET: 240 por minuto, el mismo tope, por usuario con sesión o
 * por IP sin ella (supuesto por confirmar; los alumnos de un campus comparten IP y el personal no gasta su cubo).
 */
final class PublishedThrottle
{
    public const int PER_MINUTE = 240;

    /**
     * Cuenta una actualización y aborta con 429 si la IP ya pasó el tope.
     */
    public static function hit(): void
    {
        abort_unless(RateLimiter::attempt(self::key(), self::PER_MINUTE, fn (): bool => true, 60), 429);
    }

    public static function key(): string
    {
        return 'published-schedule-livewire:'.self::who(request());
    }

    /**
     * De quién es el cubo: el usuario con sesión, o la IP.
     */
    public static function who(Request $request): string
    {
        return $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'sin-ip';
    }
}
