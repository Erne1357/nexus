<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\QueueRankings;

/**
 * Nacieron grupos (GroupsCreated) en una carga de un periodo y modalidad: encola el ranking de cada departamento de esos
 * grupos en esa modalidad, con los grupos de todos sus campus (M1). Los grupos nuevos no tienen docente, así que no
 * cambian el ranking de nadie más.
 */
final class RerankOnGroupsCreated
{
    public function __construct(private readonly QueueRankings $queue) {}

    public function handle(GroupsCreated $event): void
    {
        $departmentIds = Group::query()->whereIn('id', $event->groupIds)->distinct()->orderBy('department_id')->pluck('department_id');

        foreach ($departmentIds as $departmentId) {
            $this->queue->forScope($event->termId, $event->modalityId, $departmentId);
        }
    }
}
