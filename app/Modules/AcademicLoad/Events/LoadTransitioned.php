<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una carga cambió de estado. Se despacha después del commit: una transición que se revierte no avisa.
 */
final class LoadTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public AcademicLoad $load,
        public LoadStatus $from,
        public LoadStatus $to,
    ) {}
}
