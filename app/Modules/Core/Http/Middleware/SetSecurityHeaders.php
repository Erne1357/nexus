<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en toda respuesta de la app (tarea 4.8), con o sin proxy delante:
 *
 * - nadie la mete en un iframe (`X-Frame-Options` y `frame-ancestors`), contra el clickjacking;
 * - el navegador no adivina tipos (`nosniff`);
 * - a otro sitio solo se manda el origen como referente;
 * - sin cámara, micrófono, ubicación, pagos ni USB;
 * - `base-uri`, `form-action` y `object-src` cerrados.
 *
 * La CSP no limita scripts ni estilos: Livewire, Alpine y el servidor de Vite en desarrollo los necesitan, y una
 * política de scripts se decide aparte. HSTS lo pone el proxy que termina TLS (`docker/caddy/Caddyfile`). Una cabecera
 * que la respuesta ya trae no se pisa.
 */
final class SetSecurityHeaders
{
    /** @var array<string, string> */
    public const array HEADERS = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
