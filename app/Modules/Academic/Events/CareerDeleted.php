<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\Career;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se borró una carrera (DeleteCareer), con sus alcances ya cerrados (coordinadores). Sale después del commit.
 */
final class CareerDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Career $career) {}
}
