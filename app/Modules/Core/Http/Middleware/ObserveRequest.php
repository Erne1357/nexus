<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Observability\CanonicalLog;
use App\Modules\Core\Observability\OperationMetrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primer middleware global: fija el `request_id` (el de Caddy si es válido, uno nuevo si no), arranca el acumulador y
 * al terminar escribe la línea canónica. Las sondas no escriben línea.
 *
 * El valor entrante solo se acepta si casa con una lista cerrada de caracteres: lo demás (saltos de línea, 1 KB de
 * texto, comillas) se descarta y nunca llega al log ni a la respuesta.
 */
final class ObserveRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $sent = $request->headers->get('X-Request-Id');
        $id = is_string($sent) && preg_match('/^[A-Za-z0-9-]{8,64}\z/', $sent) === 1 ? $sent : (string) Str::uuid7();

        Context::add('request_id', $id);
        // Que nada arrastre el módulo ni el componente de la petición anterior; los pone la etiquetadora de cada petición.
        Context::forget(['module', 'livewire']);
        app(OperationMetrics::class)->start();

        $response = $next($request);

        app(OperationMetrics::class)->stop();
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->is('health', 'up')) {
            return;
        }

        app(CanonicalLog::class)->request($request, $response);
    }
}
