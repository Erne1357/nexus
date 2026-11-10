<?php

namespace App\Modules\AcademicLoad\Http\Controllers;

use App\Modules\AcademicLoad\Enums\ExportState;
use App\Modules\AcademicLoad\Exports\PublishedExport;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use App\Modules\AcademicLoad\Exports\RoomPdf;
use App\Modules\AcademicLoad\Exports\SemesterPdf;
use App\Modules\AcademicLoad\Exports\TeacherPdf;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las descargas públicas de los PDF de lo publicado (tarea 4.5): por carrera, retícula y semestre, por docente y por
 * salón, sin sesión (decisión del usuario del 10 de noviembre de 2026). Primero busca el periodo y el campus activo por
 * su clave y el sujeto en lo publicado: lo que no está publicado es 404 y no encola nada. Autoriza antes de encolar
 * (PublishedExportPolicy). Si el PDF está listo lo entrega; si no, lo pide a la cola y responde una página que se
 * recarga sola hasta que esté, o que dice que falló.
 *
 * Las rutas llevan el límite de la consulta (`published-schedule`), que alcanza para las recargas de muchos alumnos
 * con la misma IP. Encolar un PDF nuevo tiene además su propio tope por IP (`ENQUEUES_PER_MINUTE`), porque eso sí
 * cuesta trabajo de la cola (supuesto por confirmar).
 */
final class DownloadPublishedPdf
{
    public const int ENQUEUES_PER_MINUTE = 30;

    public function __construct(
        private readonly PublishedScheduleQuery $published,
        private readonly PublishedExports $exports,
    ) {}

    public function semester(Request $request, string $periodo, string $campus, string $carrera): Response|StreamedResponse
    {
        [$term, $campusModel] = $this->context($periodo, $campus);
        $plan = $request->query('reticula');
        $semester = filter_var($request->query('semestre'), FILTER_VALIDATE_INT);

        return $this->deliver($request, is_string($plan) && $semester !== false ? SemesterPdf::find($this->published, $term, $campusModel, $carrera, $plan, $semester) : null);
    }

    public function teacher(Request $request, string $periodo, string $campus, string $docente): Response|StreamedResponse
    {
        [$term, $campusModel] = $this->context($periodo, $campus);

        return $this->deliver($request, TeacherPdf::find($this->published, $term, $campusModel, $docente));
    }

    public function room(Request $request, string $periodo, string $campus, string $salon): Response|StreamedResponse
    {
        [$term, $campusModel] = $this->context($periodo, $campus);

        return $this->deliver($request, RoomPdf::find($this->published, $term, $campusModel, $salon));
    }

    /**
     * @return array{0: Term, 1: Campus}
     */
    private function context(string $termCode, string $campusCode): array
    {
        return [
            Term::query()->where('code', $termCode)->firstOrFail(),
            Campus::query()->where('code', $campusCode)->where('is_active', true)->firstOrFail(),
        ];
    }

    private function deliver(Request $request, ?PublishedExport $export): Response|StreamedResponse
    {
        abort_if($export === null, 404);
        Gate::authorize('download', $export);

        $state = $this->exports->state($export);

        if ($state === ExportState::Missing) {
            abort_unless(RateLimiter::attempt('published-exports:'.$request->ip(), self::ENQUEUES_PER_MINUTE, fn (): bool => true), 429);
            $state = $this->exports->request($export);
        }

        if ($state === ExportState::Ready) {
            return $this->exports->download($export);
        }

        return response()->view('academic-load::published.preparing', [
            'filename' => $export->filename(),
            'failure' => $state === ExportState::Failed ? $this->exports->failure($export) : null,
        ], $state === ExportState::Failed ? 503 : 202)->header('Cache-Control', 'no-store');
    }
}
