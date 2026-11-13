<?php

namespace App\Modules\Scheduling\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nacieron grupos (CreateGroupsFromProposal, tarea 3.3) en una carga de un periodo y modalidad (M1): se recalculan los
 * choques de esa modalidad, Staffing calcula su ranking y el tablero los cuenta. No lleva campus: los grupos de una
 * propuesta pueden ser de varios campus (M8), y quien lo necesita lo lee de cada grupo. Sale después del commit.
 */
final class GroupsCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $groupIds
     */
    public function __construct(
        public string $termId,
        public string $modalityId,
        public array $groupIds,
    ) {}
}
