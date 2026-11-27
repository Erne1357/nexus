<?php

namespace App\Modules\Facilities\Events;

use App\Modules\Facilities\Models\Building;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta un edificio (CreateBuilding). Sale después del commit, así que nadie se entera de un cambio que se revirtió.
 */
final class BuildingCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Building $building) {}
}
