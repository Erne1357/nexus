<?php

namespace App\Modules\Scheduling\Validation;

use App\Modules\Scheduling\Enums\SessionKind;

/**
 * Una sesión que todavía no se guarda: la que el editor quiere colocar o mover. `replacesSessionId` es la sesión del
 * grupo que se movería (no cuenta contra la candidata); sin ella, la candidata se agrega a las del grupo.
 */
final readonly class SessionCandidate
{
    public function __construct(
        public int $day,
        public string $startsAt,
        public string $endsAt,
        public ?string $roomId = null,
        public SessionKind $kind = SessionKind::Teoria,
        public ?string $replacesSessionId = null,
    ) {}
}
