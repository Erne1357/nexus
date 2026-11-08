<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Group;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se canceló un grupo: deja de contar para los choques y para la carga de su docente. Sale después del commit.
 */
final class GroupCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Group $group) {}
}
