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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las descargas públicas de los PDF de lo publicado (tarea 4.5): por carrera, retícula y semestre, por docente y por
 * salón, sin sesión (decisión del usuario del 10 de noviembre de 2026). Primero busca el periodo y el campus por su
 * clave y el sujeto en lo publicado (PublishedSchedule): lo que no está publicado es 404 y no encola nada. Si el PDF
 * está listo lo entrega; si no, lo pide a la cola y responde una página que se recarga sola hasta que esté, o que dice
 * que falló. La ruta lleva su limitador (`published-exports`), porque un invitado puede encolar.
 */
final class DownloadPublishedPdf
{
    public function __construct(
        private readonly PublishedScheduleQuery $published,
        private readonly PublishedExports $exports,
    ) {}

    public function semester(Request $request, string $periodo, string $campus, string $carrera): Response|StreamedResponse
    {
        [$term, $campusModel] = $this->context($periodo, $campus);
        $semester = filter_var($request->query('semestre'), FILTER_VALIDATE_INT);

        return $this->deliver($semester === false ? null : SemesterPdf::find($this->published, $term, $campusModel, $carrera, (string) $request->query('reticula'), $semester));
    }

    public function teacher(string $periodo, string $campus, string $docente): Response|StreamedResponse
    {
        [$term, $campusModel] = $this->context($periodo, $campus);

        return $this->deliver(TeacherPdf::find($this->published, $term, $campusModel, $docente));
    }

    public function room(string $periodo, string $campus, string $salon): Response|StreamedResponse
    {
        [$term, $campusModel] = $this->context($periodo, $campus);

        return $this->deliver(RoomPdf::find($this->published, $term, $campusModel, $salon));
    }

    /**
     * @return array{0: Term, 1: Campus}
     */
    private function context(string $termCode, string $campusCode): array
    {
        return [
            Term::query()->where('code', $termCode)->firstOrFail(),
            Campus::query()->where('code', $campusCode)->firstOrFail(),
        ];
    }

    private function deliver(?PublishedExport $export): Response|StreamedResponse
    {
        abort_if($export === null, 404);

        $state = $this->exports->request($export);

        if ($state === ExportState::Ready) {
            return $this->exports->download($export);
        }

        return response()->view('academic-load::published.preparing', [
            'filename' => $export->filename(),
            'failure' => $state === ExportState::Failed ? $this->exports->failure($export) : null,
        ], $state === ExportState::Failed ? 503 : 202)->header('Cache-Control', 'no-store');
    }
}
