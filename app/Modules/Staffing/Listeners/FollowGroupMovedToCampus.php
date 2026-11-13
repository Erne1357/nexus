<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\Scheduling\Events\GroupMovedToCampus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\FollowGroupToCampus;
use App\Modules\Staffing\Actions\QueueRankings;
use Illuminate\Support\Facades\DB;

/**
 * Un grupo cambió de campus (GroupMovedToCampus, M8). Síncrono, dentro de la transacción del cambio: las copias del
 * campus del grupo en Staffing toman el campus nuevo (FollowGroupToCampus) antes de que el cambio se confirme. Después
 * del commit encola el ranking de su departamento en el campus de antes (que ya no tiene el grupo) y en el nuevo.
 */
final class FollowGroupMovedToCampus
{
    public function __construct(
        private readonly FollowGroupToCampus $follow,
        private readonly QueueRankings $queue,
    ) {}

    public function handle(GroupMovedToCampus $event): void
    {
        $this->follow->handle($event->groupId, $event->toCampusId);
        $departmentId = Group::query()->whereKey($event->groupId)->value('department_id');

        if ($departmentId === null) {
            return;
        }

        DB::afterCommit(function () use ($event, $departmentId): void {
            $this->queue->forScope($event->termId, $event->fromCampusId, $departmentId);
            $this->queue->forScope($event->termId, $event->toCampusId, $departmentId);
        });
    }
}
