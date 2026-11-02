<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Term;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió el periodo actual (MakeTermCurrent); `$previous` es el que lo era, si lo había.
 * Sale después del commit, así que nadie se entera de un cambio que se revirtió.
 */
final class CurrentTermChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Term $term,
        public ?Term $previous,
    ) {}
}
