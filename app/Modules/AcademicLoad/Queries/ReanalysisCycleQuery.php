<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;

/**
 * Si la carga está en un ciclo de re-análisis (fase 8): su último cierre (la transición más reciente a `closed` en
 * `load_transitions`) salió de `reanalysis` y no de asignación. Lo preguntan ReopenLoad, para devolver la carga a
 * re-análisis y no a ajustes (RA-08), y PublishLoad, para guardar el motivo de la versión nueva (`SnapshotReason`, 8.4).
 */
final class ReanalysisCycleQuery
{
    public function closedFromReanalysis(AcademicLoad $load): bool
    {
        $closedFrom = $load->transitions()
            ->where('to_status', LoadStatus::Closed)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('from_status');

        return $closedFrom === LoadStatus::Reanalysis;
    }
}
