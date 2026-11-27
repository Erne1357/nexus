<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Term;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se cambiaron datos de un periodo (UpdateTerm). Sale después del commit; `$changed` son las columnas
 * que cambiaron.
 */
final class TermUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public Term $term,
        public array $changed,
    ) {}
}
