<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\Scheduling\Actions\Parallels\NotifyParallelGroupsArranged;
use App\Modules\Scheduling\Events\ParallelGroupsArranged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al armar los paralelos de una materia, avisa en cola a los coordinadores y jefes de las carreras cuyos lugares cambiaron
 * (NotifyParallelGroupsArranged). El evento sale después del commit.
 */
final class NotifyCareersOnParallelGroupsArranged implements ShouldQueue
{
    public function __construct(private readonly NotifyParallelGroupsArranged $notify) {}

    public function handle(ParallelGroupsArranged $event): void
    {
        $this->notify->handle($event);
    }
}
