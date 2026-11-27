<?php

namespace App\Modules\Facilities\Events;

use App\Modules\Facilities\Models\Room;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta un salón (CreateRoom). Sale después del commit, así que nadie se entera de un cambio que se revirtió.
 */
final class RoomCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Room $room) {}
}
