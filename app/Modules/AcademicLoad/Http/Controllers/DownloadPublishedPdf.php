<?php

namespace App\Modules\AcademicLoad\Http\Controllers;

use App\Modules\AcademicLoad\Exports\RoomPdf;
use App\Modules\AcademicLoad\Exports\SemesterPdf;
use App\Modules\AcademicLoad\Exports\TeacherPdf;
use App\Modules\AcademicLoad\Http\Controllers\Concerns\DeliversPublishedExports;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las descargas públicas de los PDF de lo publicado (tarea 4.5): por carrera, retícula y semestre, por docente y por
 * salón, sin sesión (decisión del usuario del 10 de noviembre de 2026). Primero busca el periodo, la modalidad activa
 * (M1) y, si la URL lo trae, el campus del filtro (`?campus=`) por su clave, y el sujeto en lo publicado: lo que no está publicado es 404 y no encola nada. Autoriza antes de encolar
 * (PublishedExportPolicy). Si el PDF está listo lo entrega; si no, lo pide a la cola y responde una página que se
 * recarga sola hasta que esté, o que dice que falló.
 *
 * Las rutas llevan el límite de la consulta (`published-schedule`), que alcanza para las recargas de muchos alumnos
 * con la misma IP. Encolar un PDF nuevo tiene además su propio tope por usuario con sesión o por IP (`ENQUEUES_PER_MINUTE`), porque eso sí
 * cuesta trabajo de la cola (supuesto por confirmar).
 */
final class DownloadPublishedPdf
{
    use DeliversPublishedExports;

    public function __construct(
        private readonly PublishedScheduleQuery $published,
    ) {}

    public function semester(Request $request, string $periodo, string $modalidad, string $carrera): Response|StreamedResponse
    {
        [$term, $modality, $campus] = $this->context($periodo, $modalidad, $this->campusOf($request));
        $plan = $request->query('reticula');
        $semester = filter_var($request->query('semestre'), FILTER_VALIDATE_INT);

        return $this->deliver($request, is_string($plan) && $semester !== false ? SemesterPdf::find($this->published, $term, $modality, $campus, $carrera, $plan, $semester) : null);
    }

    public function teacher(Request $request, string $periodo, string $modalidad, string $docente): Response|StreamedResponse
    {
        [$term, $modality, $campus] = $this->context($periodo, $modalidad, $this->campusOf($request));

        return $this->deliver($request, TeacherPdf::find($this->published, $term, $modality, $campus, $docente));
    }

    public function room(Request $request, string $periodo, string $modalidad, string $salon): Response|StreamedResponse
    {
        [$term, $modality, $campus] = $this->context($periodo, $modalidad, $this->campusOf($request));

        return $this->deliver($request, RoomPdf::find($this->published, $term, $modality, $campus, $salon));
    }

    /**
     * La clave del campus del filtro de la URL (`?campus=`), o null; un valor que no es texto se trata como ninguno.
     */
    private function campusOf(Request $request): ?string
    {
        $campus = $request->query('campus');

        return is_string($campus) ? $campus : null;
    }
}
