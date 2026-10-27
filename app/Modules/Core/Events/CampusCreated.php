<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Campus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta un campus (CreateCampus). Sale después del commit, así que nadie se entera de un alta
 * que se revirtió. Como toda Action de escritura (transacción, bitácora, evento), avisa aunque todavía
 * no lo escuche nadie: es la forma en que Core avisa a los módulos de la derecha del mapa.
 */
final class CampusCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Campus $campus,
    ) {}
}
