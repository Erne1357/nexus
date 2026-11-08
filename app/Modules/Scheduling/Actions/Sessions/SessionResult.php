<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ValidationResult;

/**
 * Lo que devuelven PlaceGroupSession y UpdateGroupSession: la sesión guardada (ya unida con sus vecinas), las
 * violaciones de su grupo después de guardar (para que el editor marque lo que quedó en rojo) y si se le quitó el
 * salón porque a la nueva hora estaba ocupado.
 */
final readonly class SessionResult
{
    public function __construct(
        public GroupSession $session,
        public ValidationResult $validation,
        public bool $roomDropped = false,
    ) {}
}
