<?php

namespace App\Modules\Scheduling\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nacieron grupos (CreateGroupsFromProposal, tarea 3.3): Staffing calcula su ranking y el tablero los cuenta. Sale
 * después del commit.
 */
final class GroupsCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $groupIds
     */
    public function __construct(
        public string $termId,
        public string $campusId,
        public array $groupIds,
    ) {}
}
