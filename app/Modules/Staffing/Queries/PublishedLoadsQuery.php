<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * Lo que ve el docente de las cargas publicadas (P8, confirmado el 10 de noviembre de 2026): con la carga de un
 * departamento publicada, «Mi carga» y la oferta muestran su última versión publicada; antes, lo vivo. Si se reabre,
 * siguen viendo la última versión, como el público (supuesto por confirmar). Lee lo publicado de cada campus con
 * PublishedScheduleQuery (AcademicLoad), que casi siempre viene de la caché: una consulta por los campus que publicaron
 * algo en el periodo y la de PublishedScheduleQuery por cada uno. Sin publicaciones, una sola consulta.
 */
final class PublishedLoadsQuery
{
    public function __construct(private readonly PublishedScheduleQuery $published) {}

    public function forTerm(Term $term): PublishedLoads
    {
        $campuses = Campus::query()
            ->whereIn('id', AcademicLoad::query()->select('campus_id')
                ->where('term_id', $term->id)
                ->whereIn('id', LoadSnapshot::query()->select('academic_load_id')))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return new PublishedLoads($campuses->mapWithKeys(fn (Campus $campus): array => [
            $campus->id => ['campus' => $campus, 'schedule' => $this->published->schedule($term, $campus)],
        ])->all());
    }
}
