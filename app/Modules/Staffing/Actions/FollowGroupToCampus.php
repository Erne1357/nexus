<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\StaffingRanking;

/**
 * Las copias del campus de un grupo que guarda Staffing siguen al grupo cuando cambia de campus (M8, MS9): las filas
 * de `staffing_rankings` y de `change_requests` del grupo toman el campus nuevo, así el ranking se rehace en el alcance
 * del campus nuevo sin chocar con las filas de antes y una solicitud se lista y se aplica donde ahora está el grupo
 * (MS6). Lo llama, dentro de la transacción del cambio, el listener de GroupMovedToCampus.
 *
 * Son copias del grupo para consultas y policies: no dejan bitácora (la deja el cambio de campus). El historial de
 * asignaciones (`group_teacher_assignments`) no se toca: es de solo agregar y guarda dónde se dio cada asignación.
 */
final class FollowGroupToCampus
{
    /**
     * @return int cuántas filas cambiaron
     */
    public function handle(string $groupId, string $campusId): int
    {
        return StaffingRanking::query()->where('group_id', $groupId)->where('campus_id', '<>', $campusId)->update(['campus_id' => $campusId])
            + ChangeRequest::query()->where('group_id', $groupId)->where('campus_id', '<>', $campusId)->update(['campus_id' => $campusId]);
    }
}
