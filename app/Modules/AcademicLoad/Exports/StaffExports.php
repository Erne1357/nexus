<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Http\Request;

/**
 * Los exportes del personal que ofrece la consulta, por su tipo en la URL (`/horarios/{periodo}/{campus}/exportes/{tipo}`).
 * Un exporte nuevo (el del SII, tarea 4.6) se agrega aquí y nada más.
 */
final class StaffExports
{
    /** @var array<string, class-string<StaffExport>> */
    public const array TYPES = [
        'excel' => CampusWorkbook::class,
        'departamento' => DepartmentPdf::class,
    ];

    public static function make(string $type, PublishedScheduleQuery $query, Term $term, Campus $campus, Request $request): ?StaffExport
    {
        $class = self::TYPES[$type] ?? null;

        return $class === null ? null : $class::fromRequest($query, $term, $campus, $request);
    }
}
