<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Modality;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron los datos, el estado o el perfil de flujo de una modalidad (UpdateModality). Sale después del commit, así
 * que nadie se entera de un cambio que se revirtió.
 */
final class ModalityUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed  columnas que cambiaron, en el orden de la tabla (p. ej. `['name', 'settings']`)
     */
    public function __construct(
        public Modality $modality,
        public array $changed,
    ) {}
}
