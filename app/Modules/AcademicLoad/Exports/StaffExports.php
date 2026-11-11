<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Exports\Sii\SiiFilesExport;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Authorization\OrgTree;
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
        'sii' => SiiFilesExport::class,
    ];

    /**
     * Las unidades sobre las que se pregunta el permiso de un exporte del personal (`academic_load.load.export`, campus y
     * unidad): los departamentos que publicaron en el campus y el Departamento de Servicios Escolares, que captura la
     * carga en el SII. Así lo descarga el jefe de cualquiera de esos departamentos, también el PDF de otro departamento
     * (confirmado el 10 de noviembre de 2026), Subdirección (que los cubre en el árbol) y Servicios Escolares.
     *
     * @param  list<string>  $departmentIds
     * @return list<string>
     */
    public static function units(array $departmentIds): array
    {
        $schoolServices = app(OrgTree::class)->idOf(OrgTree::SCHOOL_SERVICES);

        return array_values(array_filter([...$departmentIds, $schoolServices]));
    }

    public static function make(string $type, PublishedScheduleQuery $query, Term $term, Campus $campus, Request $request): ?StaffExport
    {
        $class = self::TYPES[$type] ?? null;

        return $class === null ? null : $class::fromRequest($query, $term, $campus, $request);
    }
}
