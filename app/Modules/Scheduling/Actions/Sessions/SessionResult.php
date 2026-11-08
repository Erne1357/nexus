<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ValidationResult;

/**
 * Lo que devuelven PlaceGroupSession, UpdateGroupSession y AssignSessionRoom: la sesión guardada (ya unida con sus
 * vecinas), las violaciones de su grupo después de guardar (para que el editor marque lo que quedó en rojo), si se le
 * quitó el salón porque a la nueva hora estaba ocupado y si se pidió el préstamo de su salón (HC-10).
 */
final readonly class SessionResult
{
    public function __construct(
        public GroupSession $session,
        public ValidationResult $validation,
        public bool $roomDropped = false,
        public bool $loanRequested = false,
    ) {}
}
