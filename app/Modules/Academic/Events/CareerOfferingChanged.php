<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\CareerCampus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió la oferta de una carrera en un campus (SetCareerOffering): se ofreció por primera vez, se
 * restauró, se activó o se desactivó. Sale después del commit, así que nadie se entera de un cambio
 * que se revirtió.
 */
final class CareerOfferingChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public CareerCampus $offering) {}
}
