<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Core\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Las modalidades en que la carga de un departamento sigue en borrador en un periodo, para sugerirle al jefe que valide
 * la propuesta de carrera en la Bandeja del departamento (CV17, confirmado el 26 de noviembre de 2026). AcademicLoad está
 * a la derecha de este módulo en el mapa: se lee la tabla `academic_loads` sin usar sus clases, y la ruta de la Bandeja
 * se pide por nombre solo si existe.
 */
final class DraftLoadsQuery
{
    /**
     * @return list<array{modality: string, url: string}> vacía sin carga en borrador, sin departamento o sin la ruta
     */
    public function inboxLinks(Term $term, ReviewOwner $owner): array
    {
        if ($owner->departmentId === null || ! Route::has('academic-load.inbox')) {
            return [];
        }

        return DB::table('academic_loads')
            ->join('modalities', 'modalities.id', '=', 'academic_loads.modality_id')
            ->where('academic_loads.term_id', $term->getKey())
            ->where('academic_loads.department_id', $owner->departmentId)
            ->where('academic_loads.status', 'draft')
            ->orderBy('modalities.name')
            ->get(['modalities.code', 'modalities.name'])
            ->map(fn (object $row): array => ['modality' => $row->name, 'url' => route('academic-load.inbox', ['modalidad' => $row->code, 'depto' => $owner->code])])
            ->all();
    }
}
