<?php

namespace App\Modules\AcademicLoad\Http\Controllers;

use App\Modules\AcademicLoad\Exports\StaffExports;
use App\Modules\AcademicLoad\Http\Controllers\Concerns\DeliversPublishedExports;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Authorization\Actor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las descargas del personal de lo publicado (tareas 4.5 y 4.6): el PDF por departamento, el Excel del campus y los
 * archivos para el SII, por su tipo (StaffExports::TYPES). Piden sesión (ruta con `auth`) y su permiso
 * (PublishedExportPolicy, antes de encolar). Lo que no está publicado es 404.
 */
final class DownloadStaffExport
{
    use DeliversPublishedExports;

    public function __construct(private readonly PublishedScheduleQuery $published) {}

    public function __invoke(Request $request, Actor $actor, string $periodo, string $campus, string $tipo): Response|StreamedResponse
    {
        $class = StaffExports::TYPES[$tipo] ?? null;
        abort_if($class === null, 404);
        // Sin el permiso en ningún alcance, 403 antes de buscar nada: no se distingue lo publicado de lo que no.
        abort_unless($actor->allowsAny($class::requiredPermission()), 403);

        [$term, $campusModel] = $this->context($periodo, $campus);

        return $this->deliver($request, StaffExports::make($tipo, $this->published, $term, $campusModel, $request));
    }
}
