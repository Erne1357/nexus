<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\Subject;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron los datos o el estado de una materia (UpdateSubject). Sale después del commit, así que
 * nadie se entera de un cambio que se revirtió.
 */
final class SubjectUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed  columnas que cambiaron (p. ej. `['credits', 'is_active']`)
     */
    public function __construct(
        public Subject $subject,
        public array $changed,
    ) {}
}
