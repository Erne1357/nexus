<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;

/**
 * Lo que ve el docente de las cargas publicadas (P8, confirmado el 10 de noviembre de 2026): con la carga de un
 * departamento publicada, «Mi carga» y la oferta muestran su última versión publicada; antes, lo vivo. Si se reabre,
 * siguen viendo la última versión, como el público (supuesto por confirmar). Lee lo publicado de cada modalidad con
 * PublishedScheduleQuery (AcademicLoad; la carga es por departamento y modalidad, M1), que casi siempre viene de la
 * caché: una consulta por las modalidades que publicaron algo en el periodo, la de PublishedScheduleQuery por cada una y
 * una por los campus de sus grupos. Sin publicaciones, una sola consulta.
 */
final class PublishedLoadsQuery
{
    public function __construct(private readonly PublishedScheduleQuery $published) {}

    public function forTerm(Term $term): PublishedLoads
    {
        $modalities = Modality::withTrashed()
            ->whereIn('id', AcademicLoad::query()->select('modality_id')
                ->where('term_id', $term->id)
                ->whereIn('id', LoadSnapshot::query()->select('academic_load_id')))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $schedules = $modalities->mapWithKeys(fn (Modality $modality): array => [
            $modality->id => ['modality' => $modality, 'schedule' => $this->published->schedule($term, $modality)],
        ])->all();

        $campusIds = collect($schedules)->flatMap(fn (array $entry): array => array_column($entry['schedule']->campuses(), 'id'))->unique()->values()->all();
        $campuses = $campusIds === [] ? [] : Campus::withTrashed()->whereIn('id', $campusIds)->get(['id', 'code', 'name'])->keyBy('id')->all();

        return new PublishedLoads($schedules, $campuses);
    }
}
