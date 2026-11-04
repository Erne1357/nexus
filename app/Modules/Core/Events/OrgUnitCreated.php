<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\OrgUnit;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta una unidad del organigrama (CreateOrgUnit). Sale después del commit, así que nadie
 * se entera de un cambio que se revirtió.
 */
final class OrgUnitCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public OrgUnit $unit,
    ) {}
}
