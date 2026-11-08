<?php

namespace App\Modules\Scheduling\Validation\State;

/**
 * El cupo de una carrera en un grupo (HC-07).
 */
final readonly class StateQuota
{
    public function __construct(
        public string $groupId,
        public string $careerId,
        public int $maxSeats,
        public bool $isOwner = false,
    ) {}
}
