<?php

namespace App\Modules\Facilities\Events;

use App\Modules\Facilities\Models\Building;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió un edificio (UpdateBuilding): `$changed` son los campos que cambiaron. Sale después del commit.
 */
final class BuildingUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed
     */
    public function __construct(public Building $building, public array $changed) {}
}
