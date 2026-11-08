<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\QueueRankings;

/**
 * Nacieron grupos (GroupsCreated): encola el ranking de cada departamento de esos grupos en su periodo y campus. Los
 * grupos nuevos no tienen docente, así que no cambian el ranking de nadie más.
 */
final class RerankOnGroupsCreated
{
    public function __construct(private readonly QueueRankings $queue) {}

    public function handle(GroupsCreated $event): void
    {
        $departmentIds = Group::query()->whereIn('id', $event->groupIds)->distinct()->pluck('department_id');

        foreach ($departmentIds as $departmentId) {
            $this->queue->forScope($event->termId, $event->campusId, $departmentId);
        }
    }
}
