<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió el calendario del periodo (SetTermStage, RemoveTermStage); `$modality` es la modalidad de la ventana, o null si
 * era la general.
 * Sale después del commit, así que nadie se entera de un cambio que se revirtió.
 */
final class TermCalendarChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Term $term,
        public ?Modality $modality,
    ) {}
}
