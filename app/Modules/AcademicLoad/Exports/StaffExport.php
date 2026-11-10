<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Http\Request;

/**
 * Un exporte de lo publicado para el personal con sesión (tareas 4.5 y 4.6): el PDF por departamento, el Excel del
 * campus y los archivos para el SII. Se ofrece en la consulta a quien tiene su permiso y se descarga por
 * `published.export` con su tipo (StaffExports::TYPES).
 */
interface StaffExport extends PublishedExport
{
    /**
     * El exporte que pide la URL (con sus parámetros, como `?departamento=`) si está publicado; null si no, para
     * responder 404 sin encolar nada.
     */
    public static function fromRequest(PublishedScheduleQuery $query, Term $term, Campus $campus, Request $request): ?static;

    /**
     * Cómo se ofrece en la consulta: «Excel del campus», «PDF del departamento».
     */
    public static function label(): string;
}
