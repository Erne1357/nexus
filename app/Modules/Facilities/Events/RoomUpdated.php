<?php

namespace App\Modules\Facilities\Events;

use App\Modules\Facilities\Models\Room;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió un salón (UpdateRoom): `$changed` son los campos que cambiaron. Sale después del commit.
 */
final class RoomUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed
     */
    public function __construct(public Room $room, public array $changed) {}
}
