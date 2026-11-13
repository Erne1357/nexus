<?php

namespace App\Modules\AcademicLoad\Http\Middleware;

use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\Core\Models\Campus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las ligas viejas de la consulta pública (`/horarios/{periodo}/{campus}/…`, con la clave de un campus como `C2`) llevan
 * a la de su modalidad con el campus como filtro (`/horarios/{periodo}/pres/…?campus=C2`; M1: lo publicado es por
 * modalidad). Así siguen sirviendo las ligas guardadas o impresas de antes. La modalidad del campus sale del puente
 * `campuses.modality_id`. Si el segmento ya es una modalidad activa, o no es un campus activo con modalidad activa, la
 * petición sigue igual (y la página da 404 si no existe). Conserva los demás parámetros de la URL.
 */
final class RedirectCampusToModality
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $segment = $route?->parameter('modalidad');

        if (! is_string($segment) || ! PublishedContextQuery::isCode($segment) || PublishedContextQuery::modality($segment) !== null) {
            return $next($request);
        }

        $campus = Campus::query()->with('catalogModality')->where('code', $segment)->where('is_active', true)->first();
        $modality = $campus?->catalogModality;

        if ($modality === null || ! $modality->is_active || $route->getName() === null) {
            return $next($request);
        }

        return redirect()->route($route->getName(), [
            ...$route->parameters(),
            'modalidad' => PublishedContextQuery::modalityKey($modality),
            'campus' => $campus->code,
            ...collect($request->query())->except('campus')->all(),
        ], 301);
    }
}
