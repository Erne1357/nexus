<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\Career;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron la clave, el nombre, el departamento o el estado de una carrera (UpdateCareer). Sale después del
 * commit, así que nadie se entera de un cambio que se revirtió.
 */
final class CareerUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed  columnas que cambiaron (p. ej. `['name', 'is_active']`)
     */
    public function __construct(
        public Career $career,
        public array $changed,
    ) {}
}
