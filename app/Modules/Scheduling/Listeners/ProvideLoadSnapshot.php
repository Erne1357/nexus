<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\AcademicLoad\Events\LoadSnapshotRequested;
use App\Modules\Scheduling\Queries\LoadSnapshotQuery;

/**
 * Da los grupos de la foto cuando AcademicLoad publica una carga (tarea 4.3): AcademicLoad no ve grupos, así que los
 * pide con LoadSnapshotRequested dentro de la transacción de la publicación. Solo lee.
 */
final class ProvideLoadSnapshot
{
    public function __construct(private readonly LoadSnapshotQuery $snapshot) {}

    public function handle(LoadSnapshotRequested $event): void
    {
        $event->provideGroups($this->snapshot->groups($event->load));
    }
}
