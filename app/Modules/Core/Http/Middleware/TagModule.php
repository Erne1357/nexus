<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Etiqueta la petición con su módulo (alias kebab) para que `request.done` diga de quién es cada ruta.
 * ModuleServiceProvider lo pone en el grupo de rutas de cada módulo; lo de Core queda con el `core` por omisión.
 */
final class TagModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        Context::add('module', $module);

        return $next($request);
    }
}
