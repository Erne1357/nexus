<?php

namespace App\Modules\AcademicLoad\Http\Middleware;

use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las ligas viejas de la consulta pública (`/horarios/{periodo}/{campus}/…`, con la clave de un campus como `C2`) llevan
 * a la de su modalidad con el campus como filtro (`/horarios/{periodo}/pres/…?campus=C2`; M1: lo publicado es por
 * modalidad). Así siguen sirviendo las ligas guardadas o impresas de antes. La modalidad sale de lo publicado, no de una
 * clave: la única modalidad activa con grupos publicados en ese campus en el periodo
 * (PublishedScheduleQuery::modalitiesWithGroupsIn). Si ninguna o varias los tienen, 404: la liga no dice cuál.
 *
 * Si el segmento es una modalidad activa, la petición sigue igual; si no es la clave de un campus activo, también (y la
 * página da 404). Conserva los demás parámetros de la consulta, salvo los que se llaman como uno del camino.
 */
final class RedirectCampusToModality
{
    public function __construct(private readonly PublishedScheduleQuery $published) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $segment = $route?->parameter('modalidad');

        if (! is_string($segment) || ! PublishedContextQuery::isCode($segment) || PublishedContextQuery::modality($segment) !== null) {
            return $next($request);
        }

        $campus = Campus::query()->where('code', $segment)->where('is_active', true)->first();

        if ($campus === null || $route->getName() === null) {
            return $next($request);
        }

        $termCode = $route->parameter('periodo');
        $term = is_string($termCode) && PublishedContextQuery::isCode($termCode) ? Term::query()->where('code', $termCode)->first() : null;
        $modalities = $term === null ? [] : $this->published->modalitiesWithGroupsIn($term, $campus->code);

        abort_unless(count($modalities) === 1, 404);

        // La consulta no puede pisar los parámetros del camino (`?docente=x` en `/docente/<uuid>`): se descartan los suyos.
        return redirect()->route($route->getName(), [
            ...$route->parameters(),
            'modalidad' => PublishedContextQuery::modalityKey($modalities[0]),
            'campus' => $campus->code,
            ...collect($request->query())->except(['campus', 'modalidad', ...array_keys($route->parameters())])->all(),
        ], 301);
    }
}
