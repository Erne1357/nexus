<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ValidationResult;

/**
 * Lo que devuelven PlaceGroupSession, UpdateGroupSession y AssignSessionRoom: la sesión guardada (ya unida con sus
 * vecinas), las violaciones de su grupo después de guardar (para que el editor marque lo que quedó en rojo), si se le
 * quitó el salón porque a la nueva hora estaba ocupado, si se pidió el préstamo de su salón (HC-10) y si no se pudo
 * pedir porque el préstamo vivo de otro grupo ya aparta el salón a esa hora (queda HC-10 «sin préstamo»).
 */
final readonly class SessionResult
{
    public function __construct(
        public GroupSession $session,
        public ValidationResult $validation,
        public bool $roomDropped = false,
        public bool $loanRequested = false,
        public bool $loanBlocked = false,
    ) {}
}
