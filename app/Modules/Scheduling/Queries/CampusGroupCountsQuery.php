<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Models\Group;

/**
 * Cuántos grupos sin cancelar tiene una carga en cada campus (M1: la carga es de un departamento en una modalidad, con
 * los grupos de todos sus campus): la cuenta de cada botón del filtro de campus en las pantallas de la carga (maqueta
 * U2, opción A) y el reparto del cierre («2 grupos en C1 y 1 en C2»). Una consulta; sin estado propio, para Octane.
 */
final class CampusGroupCountsQuery
{
    /**
     * Los grupos sin cancelar de la carga por clave del campus donde se dan, por clave; sin los que no tienen campus (una
     * modalidad sin salones, MS4).
     *
     * @return array<string, int>
     */
    public function ofLoad(AcademicLoad $load): array
    {
        return Group::query()->active()->toBase()
            ->join('campuses', 'campuses.id', '=', 'groups.campus_id')
            ->where('groups.term_id', $load->term_id)
            ->where('groups.modality_id', $load->modality_id)
            ->where('groups.department_id', $load->department_id)
            ->groupBy('campuses.code')
            ->orderBy('campuses.code')
            ->selectRaw('campuses.code, count(*) as total')
            ->pluck('total', 'code')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
