<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\Scheduling\Events\GroupMovedToCampus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\QueueRankings;
use Illuminate\Support\Facades\DB;

/**
 * Un grupo cambió de campus (GroupMovedToCampus, M8) dentro de la carga de su modalidad. Staffing no guarda copias del
 * campus del grupo (rankings, asignaciones y solicitudes lo leen del grupo), así que no hay nada que cambiar en la
 * transacción. Síncrono, para leer el departamento del grupo dentro de ella; después del commit encola el ranking de su
 * departamento en su modalidad, que ya tiene los dos campus (M1): los traslados de SC-09 cambian con el campus. El
 * GroupScheduleChanged que MoveGroupToCampus manda después del commit también lo encola (RerankOnGroupChange, con los
 * alcances de su docente); ComputeRankings es único por alcance mientras espera, así que no se calcula dos veces.
 */
final class FollowGroupMovedToCampus
{
    public function __construct(private readonly QueueRankings $queue) {}

    public function handle(GroupMovedToCampus $event): void
    {
        $departmentId = Group::query()->whereKey($event->groupId)->value('department_id');

        if ($departmentId === null) {
            return;
        }

        DB::afterCommit(fn () => $this->queue->forScope($event->termId, $event->modalityId, $departmentId));
    }
}
