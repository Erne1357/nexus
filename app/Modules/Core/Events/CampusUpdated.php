<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Campus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron los datos, el estado o el perfil de flujo de un campus (UpdateCampus). Sale después del
 * commit, así que nadie se entera de un cambio que se revirtió.
 *
 * Es la forma en que Core avisa a quien dependa del campus sin conocerlo: la caché del perfil de
 * flujo (docs/arquitectura/arquitectura.md, "Caché") y los módulos de la derecha del mapa que
 * revaliden con el perfil nuevo. Todavía no lo escucha nadie.
 */
final class CampusUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed  columnas que cambiaron, en el orden de la tabla (p. ej. `['name', 'settings']`)
     */
    public function __construct(
        public Campus $campus,
        public array $changed,
    ) {}
}
