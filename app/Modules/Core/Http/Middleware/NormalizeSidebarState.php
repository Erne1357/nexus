<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MaryUI guarda en la sesión si la barra lateral está colapsada y <x-main> imprime ese valor dentro
 * de x-data, donde Alpine lo ejecuta. Antes de pintar cualquier página, todo valor distinto de
 * 'true' o 'false' se reescribe a 'false', venga de donde venga (la ruta de MaryUI ya está
 * reemplazada en Core/Routes/web.php). Va en el grupo `web` (bootstrap/app.php).
 */
final class NormalizeSidebarState
{
    public const string KEY = 'mary-sidebar-collapsed';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        if ($session->has(self::KEY) && ! in_array($session->get(self::KEY), ['true', 'false'], true)) {
            $session->put(self::KEY, 'false');
        }

        return $next($request);
    }
}
