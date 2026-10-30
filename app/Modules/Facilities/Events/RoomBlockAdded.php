<?php

namespace App\Modules\Facilities\Events;

use App\Modules\Facilities\Models\RoomBlock;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se agregó un bloqueo a un salón (AddRoomBlock). Sale después del commit, así que nadie se entera de un cambio que se revirtió.
 */
final class RoomBlockAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public RoomBlock $block) {}
}
