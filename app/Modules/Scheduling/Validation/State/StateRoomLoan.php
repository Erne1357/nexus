<?php

namespace App\Modules\Scheduling\Validation\State;

/**
 * Un préstamo de salón vivo de un grupo (HC-10): aceptado o todavía propuesto, con horas en minutos.
 */
final readonly class StateRoomLoan
{
    public function __construct(
        public string $groupId,
        public string $roomId,
        public int $day,
        public int $start,
        public int $end,
        public bool $isAccepted,
    ) {}
}
