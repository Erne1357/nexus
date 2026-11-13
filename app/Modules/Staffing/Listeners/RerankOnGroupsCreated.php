<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\QueueRankings;

/**
 * Nacieron grupos (GroupsCreated): encola el ranking de cada departamento de esos grupos en su periodo y en el campus
 * de cada grupo (el evento ya no lleva campus: una propuesta crea grupos de varios campus, M8). Los grupos nuevos no
 * tienen docente, así que no cambian el ranking de nadie más.
 */
final class RerankOnGroupsCreated
{
    public function __construct(private readonly QueueRankings $queue) {}

    public function handle(GroupsCreated $event): void
    {
        $scopes = Group::query()->whereIn('id', $event->groupIds)->distinct()->orderBy('campus_id')->orderBy('department_id')->get(['campus_id', 'department_id']);

        foreach ($scopes as $scope) {
            $this->queue->forScope($event->termId, $scope->campus_id, $scope->department_id);
        }
    }
}
