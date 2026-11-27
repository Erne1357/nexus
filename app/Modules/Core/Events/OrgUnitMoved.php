<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\OrgUnit;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se movió una unidad del organigrama a otra madre (MoveOrgUnit). Sale después del commit, así que nadie
 * se entera de un cambio que se revirtió.
 */
final class OrgUnitMoved implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public OrgUnit $unit,
    ) {}
}
